<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\ContentWriter;
use Quire\Content\Event\ContentCreating;
use Quire\Content\Exception\InvalidContentException;
use Quire\Content\Job\PublishDueContent;
use Quire\Content\Status;
use Quire\Fixtures\Hello\Job\SendGreeting;
use Quire\Fixtures\Hello\Job\SendGreetingHandler;
use Quire\Fixtures\Hello\Job\TickHandler;
use Quire\Jobs\Internal\Heartbeats;
use Quire\Jobs\Internal\QuireTransportFactory;
use Quire\Jobs\Internal\Scheduler;
use Quire\Jobs\Internal\WorkerListener;
use Quire\Jobs\Job;
use Quire\Jobs\JobStatus;
use Quire\Jobs\ScheduledTasks;
use Quire\Runtime\BackgroundRefresher;
use Quire\Runtime\StaleBuildDetector;
use Quire\Storage\Job\CollectGarbage;
use Quire\Testing\JobRunner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;

final class JobsTest extends KernelTestCase
{
    private Connection $connection;

    private MessageBusInterface $bus;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->connection->beginTransaction();
        $this->bus = $container->get(MessageBusInterface::class);
        SendGreetingHandler::$greeted = [];
        TickHandler::$ticks = 0;
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testJobsExistOnlyIfTheirTransactionCommits(): void
    {
        $writer = self::getContainer()->get(ContentWriter::class);
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(ContentCreating::class, function (ContentCreating $event): void {
            // Inside the change's transaction: the job is saved with the change, or not at all.
            $this->bus->dispatch(new SendGreeting($event->command->title));
        });

        $writer->create(new CreateContent('post', 'Ada'));

        try {
            $writer->create(new CreateContent('post', 'Grace', parentId: 'not-an-id'));
            self::fail('Created an invalid item.');
        } catch (InvalidContentException) {
        }

        self::assertSame(['default'], $this->connection->fetchFirstColumn('SELECT queue_name FROM quire.job'), 'Only the job of the item that was saved.');
        self::assertSame([], SendGreetingHandler::$greeted, 'Nothing runs before a worker takes it.');

        self::assertSame(1, JobRunner::run(self::getContainer()));
        self::assertSame(['Ada'], SendGreetingHandler::$greeted);
        self::assertEquals(0, $this->connection->fetchOne('SELECT count(*) FROM quire.job'));
    }

    public function testFailedJobsAreRetriedLater(): void
    {
        $this->bus->dispatch(new SendGreeting('nobody'));
        $transport = self::getContainer()->get('messenger.transport.'.Job::TRANSPORT);
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event): void {
            $event->getWorker()->stop();
        });

        new Worker([Job::TRANSPORT => $transport], $this->bus, $dispatcher)->run();

        $retry = $this->connection->fetchAssociative("SELECT queue_name, available_at > (now() AT TIME ZONE 'UTC') AS later FROM quire.job");
        self::assertSame(['queue_name' => 'default', 'later' => true], $retry, 'Back in the queue, for a retry in 10 seconds.');

        $report = self::getContainer()->get(JobStatus::class)->report();
        self::assertSame([0, 1, 0], [$report->waiting, $report->delayed, $report->failed]);
    }

    public function testScheduledTasksRunOncePerOccurrence(): void
    {
        $scheduler = self::getContainer()->get(Scheduler::class);
        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-10-24 '.$time.' UTC');

        self::assertSame([], $scheduler->run('test', $at('10:01')), 'Tasks seen the first time wait for their next occurrence.');
        self::assertSame(['quire.publish-due'], $scheduler->run('test', $at('10:04')), 'Every minute; the tick every 5.');

        self::assertSame(['quire.publish-due', 'quire-fixture-hello.tick'], $scheduler->run('web-1', $at('10:05:30')));
        self::assertSame([], $scheduler->run('web-2', $at('10:05:40')), 'Another worker finds it done.');
        self::assertSame(['quire.publish-due'], $scheduler->run('web-2', $at('10:06:01')));

        // The workers were down for an hour: the last missed occurrence runs, once.
        self::assertSame(['quire.publish-due', 'quire-fixture-hello.tick'], $scheduler->run('web-1', $at('11:07')));
        self::assertSame(['quire.publish-due', 'quire-fixture-hello.tick'], array_column($this->connection->fetchAllAssociative("SELECT task, to_char(last_due_at AT TIME ZONE 'UTC', 'HH24:MI') AS due, last_run_by FROM quire.job_schedule WHERE task <> 'quire.collect-garbage' ORDER BY task DESC"), 'task'));
        self::assertSame('11:05', $this->connection->fetchOne("SELECT to_char(last_due_at AT TIME ZONE 'UTC', 'HH24:MI') FROM quire.job_schedule WHERE task = 'quire-fixture-hello.tick'"));

        self::assertSame(6, JobRunner::run(self::getContainer()), '4 × publishing due content, 2 ticks.');
        self::assertSame(2, TickHandler::$ticks);
    }

    public function testWorkersCheckingAtTheSameTimeRunATaskOnce(): void
    {
        $tasks = self::getContainer()->get(ScheduledTasks::class);
        $first = new \DateTimeImmutable('2026-10-24 10:01 UTC');
        $due = new \DateTimeImmutable('2026-10-24 10:05:30 UTC');
        $other = self::getContainer()->get(Scheduler::class);
        $other->run('test', $first);
        $dispatched = new \ArrayObject();
        $bus = $this->bus;
        // While web-1 dispatches its first task, web-2 runs the schedule: web-1 read the schedule before that.
        $interleaving = new class($bus, $other, $due, $dispatched) implements MessageBusInterface {
            private bool $interleaved = false;

            /**
             * @param \ArrayObject<int, string> $dispatched
             */
            public function __construct(private readonly MessageBusInterface $bus, private readonly Scheduler $other, private readonly \DateTimeImmutable $due, private \ArrayObject $dispatched)
            {
            }

            public function dispatch(object $message, array $stamps = []): \Symfony\Component\Messenger\Envelope
            {
                if (!$this->interleaved) {
                    $this->interleaved = true;

                    foreach ($this->other->run('web-2', $this->due) as $task) {
                        $this->dispatched[] = 'web-2: '.$task;
                    }
                }

                $this->dispatched[] = 'web-1: '.$message::class;

                return $this->bus->dispatch($message, $stamps);
            }
        };

        $ran = new Scheduler($this->connection, $tasks, $interleaving)->run('web-1', $due);

        self::assertSame(['quire.publish-due'], $ran);
        self::assertSame(['web-2: quire-fixture-hello.tick', 'web-1: '.PublishDueContent::class], [...$dispatched], 'web-1 found the tick done when it came to it, though it had read it as due.');
    }

    public function testATaskIsOnlyMarkedRunWhenItsJobIsQueued(): void
    {
        $scheduler = self::getContainer()->get(Scheduler::class);
        $scheduler->run('test', new \DateTimeImmutable('2026-10-24 10:01 UTC'));
        $failing = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): \Symfony\Component\Messenger\Envelope
            {
                throw new \RuntimeException('The queue is unavailable.');
            }
        };
        $tasks = self::getContainer()->get(ScheduledTasks::class);

        try {
            new Scheduler($this->connection, $tasks, $failing)->run('test', new \DateTimeImmutable('2026-10-24 10:05:30 UTC'));
            self::fail('The bus failed.');
        } catch (\RuntimeException) {
        }

        self::assertSame(['quire.publish-due', 'quire-fixture-hello.tick'], $scheduler->run('test', new \DateTimeImmutable('2026-10-24 10:05:40 UTC')), 'Still due.');
    }

    public function testStatusReportsWhatNeedsAttention(): void
    {
        $status = self::getContainer()->get(JobStatus::class);
        $now = new \DateTimeImmutable('2026-10-24 12:00 UTC');
        $this->connection->executeStatement("INSERT INTO quire.job (body, headers, queue_name, created_at, available_at) VALUES ('x', '[]', 'default', '2026-10-24 11:40', '2026-10-24 11:40'), ('x', '[]', 'default', '2026-10-24 11:50', '2026-10-24 13:00'), ('x', '[]', 'failed', '2026-10-24 09:00', '2026-10-24 09:00')");

        $report = $status->report($now);
        self::assertTrue($report->queued);
        self::assertSame([1, 1, 0, 1], [$report->waiting, $report->delayed, $report->running, $report->failed]);
        self::assertSame('2026-10-24T11:40:00+00:00', $report->oldestWaiting?->format('c'));
        self::assertCount(3, $report->problems());
        self::assertStringContainsString('1 job(s) have been waiting for 20 minutes, and no worker is running.', $report->problems()[0]);
        self::assertStringContainsString('Nothing has run the scheduled tasks', $report->problems()[1]);
        self::assertStringContainsString('1 job(s) failed after their retries.', $report->problems()[2]);

        $this->connection->executeStatement("INSERT INTO quire.job_worker (id, host, pid, transports, seen_at) VALUES ('w1', 'web-1', 7, 'quire', '2026-10-24 11:59'), ('old', 'web-2', 8, 'quire', '2026-10-24 10:00')");
        $report = $status->report($now);
        self::assertSame(['w1'], array_column($report->workers, 'id'), 'Workers not seen for 10 minutes are gone.');
        self::assertCount(1, $report->problems(), 'Only the failed job.');
    }

    public function testWorkersReportTheyAreAliveAndRestartForNewExtensions(): void
    {
        $container = self::getContainer();
        /** @var \ArrayObject<int, string> $refreshes */
        $refreshes = new \ArrayObject();
        $refresher = new BackgroundRefresher('/app', 'test', spawn: static function (string $command) use ($refreshes): void {
            $refreshes[] = $command;
        });
        $current = new StaleBuildDetector($this->connection, $refresher, $container->getParameter('quire.extensions.state_hash'), 5);
        $time = 1000;
        $listener = new WorkerListener($container->get(Heartbeats::class), $container->get(Scheduler::class), $current, $refresher, clock: static function () use (&$time): int {
            return $time;
        });
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerStartedEvent::class, $listener->onStarted(...));
        $dispatcher->addListener(WorkerRunningEvent::class, $listener->onRunning(...));
        $dispatcher->addListener(WorkerStoppedEvent::class, $listener->onStopped(...));
        $runs = 0;
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use (&$runs, &$time): void {
            $time += 5;

            if (++$runs >= 4) {
                $event->getWorker()->stop();
            }
        });

        $worker = new Worker([Job::TRANSPORT => new InMemoryTransport()], $this->bus, $dispatcher);
        $seen = [];
        $dispatcher->addListener(WorkerRunningEvent::class, function () use (&$seen): void {
            $seen[] = $this->connection->fetchOne('SELECT transports FROM quire.job_worker');
        }, -10);
        $worker->run(['sleep' => 0]);

        self::assertSame(['quire', 'quire', 'quire', 'quire'], $seen, 'Alive while it runs.');
        self::assertEquals(0, $this->connection->fetchOne('SELECT count(*) FROM quire.job_worker'), 'And gone when it stops.');
        self::assertCount(0, $refreshes);

        // An extension was enabled elsewhere: this worker's build is stale.
        $stale = new StaleBuildDetector($this->connection, $refresher, 'another-build', 5);
        $listener = new WorkerListener($container->get(Heartbeats::class), $container->get(Scheduler::class), $stale, $refresher);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerStartedEvent::class, $listener->onStarted(...));
        $worker = new Worker([Job::TRANSPORT => new InMemoryTransport()], $this->bus, $dispatcher);
        $worker->run(['sleep' => 0]);

        self::assertCount(1, $refreshes, 'It rebuilt, then stopped.');
        self::assertStringContainsString('quire:app:refresh', $refreshes[0] ?? '');
        self::assertStringNotContainsString(' &', $refreshes[0] ?? '', 'And waited for the rebuild.');

        // Workers of other transports are none of Quire's business.
        $refreshes->exchangeArray([]);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerStartedEvent::class, $listener->onStarted(...));
        $dispatcher->addListener(WorkerRunningEvent::class, static fn (WorkerRunningEvent $event) => $event->getWorker()->stop());
        new Worker(['async' => new InMemoryTransport()], $this->bus, $dispatcher)->run(['sleep' => 0]);
        self::assertCount(0, $refreshes);
    }

    public function testQuireDsns(): void
    {
        $factory = self::getContainer()->get(QuireTransportFactory::class);

        self::assertTrue($factory->supports('quire://', []));
        self::assertFalse($factory->supports('doctrine://default', []));
        self::assertInstanceOf(DoctrineTransport::class, $factory->createTransport('quire://default?queue=failed', [], new PhpSerializer()));
        self::assertInstanceOf(DoctrineTransport::class, $factory->createTransport('quire://?notify=1', [], new PhpSerializer()));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown option(s) in "quire://?table_name=x": table_name.');
        $factory->createTransport('quire://?table_name=x', [], new PhpSerializer());
    }

    public function testCoreSchedulesPublishingAndGarbageCollection(): void
    {
        $writer = self::getContainer()->get(ContentWriter::class);
        $post = $writer->create(new CreateContent('post', 'Later'));
        $post = $writer->transition($post->id, $post->version, Status::SCHEDULE, new \DateTimeImmutable('+1 hour'));
        $this->connection->executeStatement("UPDATE quire.content SET published_at = now() - INTERVAL '1 minute' WHERE id = ?", [$post->id]);

        $this->bus->dispatch(new PublishDueContent());
        $this->bus->dispatch(new CollectGarbage());
        self::assertSame(2, JobRunner::run(self::getContainer()));

        self::assertSame(Status::PUBLISHED, $this->connection->fetchOne('SELECT status FROM quire.content WHERE id = ?', [$post->id]));
        self::assertSame(['quire.publish-due', 'quire.collect-garbage', 'quire-fixture-hello.tick'], array_keys(self::getContainer()->get(ScheduledTasks::class)->all()));
    }
}
