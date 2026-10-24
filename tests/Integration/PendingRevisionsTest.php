<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\Command\UpdateContent;
use Quire\Content\Command\UpdatePendingRevision;
use Quire\Content\Content;
use Quire\Content\ContentRenderer;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Event\ContentUpdated;
use Quire\Content\Event\PendingRevisionDiscarded;
use Quire\Content\Event\PendingRevisionPublished;
use Quire\Content\Event\PendingRevisionPublishFailed;
use Quire\Content\Event\PendingRevisionReviewed;
use Quire\Content\Event\PendingRevisionSaved;
use Quire\Content\Event\PendingRevisionSubmitted;
use Quire\Content\Exception\ContentConflictException;
use Quire\Content\Exception\ContentNotFoundException;
use Quire\Content\Exception\InvalidContentException;
use Quire\Content\Exception\MergeConflictException;
use Quire\Content\Exception\PendingRevisionConflictException;
use Quire\Content\Revision\PendingRevision;
use Quire\Content\Revision\PendingRevisionRepository;
use Quire\Content\Revision\PendingRevisionWriter;
use Quire\Content\Revision\ReviewState;
use Quire\Content\Revision\RevisionRepository;
use Quire\Content\Revision\Verdict;
use Quire\Content\Status;
use Quire\Formatter\Formatter;
use Quire\Settings\Settings;
use Quire\User\CreateUser;
use Quire\User\User;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class PendingRevisionsTest extends KernelTestCase
{
    private const string BODY = "# Opening hours\n\nMonday to Friday, 9 to 5.\n\nClosed on Sundays.\n";

    private Connection $connection;

    private ContentWriter $writer;

    private PendingRevisionWriter $pending;

    private PendingRevisionRepository $repository;

    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->connection->beginTransaction();
        $this->writer = $container->get(ContentWriter::class);
        $this->pending = $container->get(PendingRevisionWriter::class);
        $this->repository = $container->get(PendingRevisionRepository::class);

        foreach ([PendingRevisionSaved::class, PendingRevisionSubmitted::class, PendingRevisionReviewed::class, PendingRevisionDiscarded::class, PendingRevisionPublished::class, ContentUpdated::class] as $event) {
            $container->get(EventDispatcherInterface::class)->addListener($event, function (object $event): void {
                $this->events[] = $event;
            });
        }
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testChangesGoLiveOnlyWhenPublished(): void
    {
        $ada = $this->user('ada');
        $post = $this->published();

        $pending = $this->pending->create($post->id, $ada->id, 'Longer hours');
        self::assertSame([$post->version, ReviewState::Editing, $ada->id], [$pending->baseVersion, $pending->state, $pending->authorId]);
        self::assertEquals($pending->base, $pending->proposed, 'It starts from the live text.');

        // Autosaves.
        $pending = $this->pending->update(new UpdatePendingRevision($pending->id, $pending->version)->body(str_replace('9 to 5', '9 to 6', self::BODY)));
        $pending = $this->pending->update(new UpdatePendingRevision($pending->id, $pending->version)->title('Opening times')->field('mood', 'happy'));
        self::assertSame(3, $pending->version);
        self::assertSame(['title', 'body', 'fields.mood'], array_keys($pending->diff()->changes));

        $live = $this->content($post);
        self::assertSame(['Hours', self::BODY, $post->version], [$live->title, $live->body, $live->version], 'Visitors still see the published text.');

        $this->events = [];
        $after = $this->writer->publishPendingRevision($pending->id, $pending->version, $ada->id);

        self::assertSame(['Opening times', str_replace('9 to 5', '9 to 6', self::BODY), 'happy', Status::PUBLISHED], [$after->title, $after->body, $after->field('mood'), $after->status]);
        self::assertNull($this->repository->find($pending->id), 'Published changes are no longer pending.');

        $revision = $this->revisions($post)[0];
        self::assertSame([$after->version, 'Longer hours', $ada->id, null], [$revision->contentVersion, $revision->message, $revision->authorId, $revision->approvedBy]);

        $events = $this->recorded();
        self::assertSame([ContentUpdated::class, PendingRevisionPublished::class], array_map(static fn (object $event): string => $event::class, $events));
        self::assertInstanceOf(PendingRevisionPublished::class, $events[1]);
        self::assertSame($ada->id, $events[1]->actorId);
    }

    public function testChangesPublishedMeanwhileAreMerged(): void
    {
        $post = $this->published();
        $alice = $this->pending->create($post->id);
        $bob = $this->pending->create($post->id);

        $alice = $this->pending->update(new UpdatePendingRevision($alice->id, 1)->body(str_replace('# Opening hours', '# Opening times', self::BODY)));
        $bob = $this->pending->update(new UpdatePendingRevision($bob->id, 1)->body(str_replace('Sundays.', 'Sundays and holidays.', self::BODY))->field('rating', 4));
        // And a direct edit of the item.
        $this->writer->update(new UpdateContent($post->id, $this->content($post)->version)->field('mood', 'sad'));

        $this->writer->publishPendingRevision($alice->id, $alice->version);
        $after = $this->writer->publishPendingRevision($bob->id, $bob->version);

        self::assertSame("# Opening times\n\nMonday to Friday, 9 to 5.\n\nClosed on Sundays and holidays.\n", $after->body);
        self::assertSame(['mood' => 'sad', 'rating' => 4], array_intersect_key($after->fields, ['mood' => 1, 'rating' => 1]));
    }

    public function testConflictsAreResolvedByRebasing(): void
    {
        $post = $this->published();
        $pending = $this->pending->create($post->id);
        $pending = $this->pending->update(new UpdatePendingRevision($pending->id, 1)->body(str_replace('9 to 5', '9 to 6', self::BODY)));
        $live = $this->writer->update(new UpdateContent($post->id, $post->version)->body(str_replace('9 to 5', '10 to 5', self::BODY)));

        try {
            $this->writer->publishPendingRevision($pending->id, $pending->version);
            self::fail('Both changed the same line.');
        } catch (MergeConflictException $e) {
            self::assertSame(['body'], array_keys($e->conflicts));
        }

        self::assertSame($live->version, $this->content($post)->version, 'Nothing was published.');
        self::assertSame($pending->version, $this->repository->get($pending->id)->version);
        self::assertSame(['body'], array_keys($this->repository->merge($pending)->conflicts), 'The merge can be checked before publishing.');

        try {
            $this->pending->update(new UpdatePendingRevision($pending->id, $pending->version)->rebase($post->version));
            self::fail('Rebased onto an old version.');
        } catch (ContentConflictException $e) {
            self::assertSame($live->version, $e->currentVersion);
        }

        $resolved = $this->pending->update(new UpdatePendingRevision($pending->id, $pending->version)->body(str_replace('9 to 5', '10 to 6', self::BODY))->rebase($live->version));
        self::assertSame([$live->version, $live->body], [$resolved->baseVersion, $resolved->base->body]);

        $after = $this->writer->publishPendingRevision($resolved->id, $resolved->version);
        self::assertStringContainsString('10 to 6', $after->body);
    }

    public function testWorkInProgressIsSavedAndCheckedOnPublishing(): void
    {
        $post = $this->published();
        $pending = $this->pending->create($post->id);

        try {
            $this->pending->update(new UpdatePendingRevision($pending->id, $pending->version)->body('x', 'docx'));
            self::fail('An unknown format can\'t even be previewed.');
        } catch (InvalidContentException $e) {
            self::assertSame(['body_format'], array_keys($e->violations));
        }

        $pending = $this->pending->update(new UpdatePendingRevision($pending->id, $pending->version)->title('')->field('rating', 'lots'));

        try {
            $this->pending->update(new UpdatePendingRevision($pending->id, 1)->title('From another tab'));
            self::fail('Saved over a newer version.');
        } catch (PendingRevisionConflictException $e) {
            self::assertSame([1, 2], [$e->expectedVersion, $e->currentVersion]);
        }

        try {
            $this->writer->publishPendingRevision($pending->id, $pending->version);
            self::fail('Published an empty title.');
        } catch (InvalidContentException $e) {
            self::assertSame(['title', 'fields.rating'], array_keys($e->violations));
        }

        try {
            $this->writer->publishPendingRevision($pending->id, 1);
            self::fail('Published what the publisher didn\'t see.');
        } catch (PendingRevisionConflictException) {
        }

        self::assertNotNull($this->repository->find($pending->id), 'Still pending.');
    }

    public function testReviews(): void
    {
        [$ada, $grace] = [$this->user('ada'), $this->user('grace')];
        self::getContainer()->get(Settings::class)->set(ContentWriter::APPROVAL_SETTING, true);
        $post = $this->published();
        $pending = $this->pending->create($post->id, $ada->id, 'Longer hours');
        $pending = $this->pending->update(new UpdatePendingRevision($pending->id, 1)->title('Opening times'));

        try {
            $this->writer->publishPendingRevision($pending->id, $pending->version);
            self::fail('The site requires approval.');
        } catch (InvalidContentException $e) {
            self::assertSame(['state'], array_keys($e->violations));
        }

        self::assertSame(ReviewState::InReview, $this->pending->submit($pending->id, $pending->version, $ada->id)->state);

        try {
            $this->pending->review($pending->id, $pending->version, Verdict::Approve, $ada->id);
            self::fail('Approved their own changes.');
        } catch (InvalidContentException $e) {
            self::assertSame(['reviewer_id'], array_keys($e->violations));
        }

        $this->pending->review($pending->id, $pending->version, Verdict::RequestChanges, $grace->id, 'Say which days.');
        self::assertSame(ReviewState::ChangesRequested, $this->repository->get($pending->id)->state);

        $pending = $this->pending->update(new UpdatePendingRevision($pending->id, $pending->version)->title('Opening times, Monday to Friday'));
        self::assertSame(ReviewState::ChangesRequested, $pending->state, 'Until the author asks again.');
        $this->pending->submit($pending->id, $pending->version);

        try {
            $this->pending->review($pending->id, 1, Verdict::Approve, $grace->id);
            self::fail('Approved a version that was changed since.');
        } catch (PendingRevisionConflictException) {
        }

        $this->pending->review($pending->id, $pending->version, Verdict::Approve, $grace->id);
        $this->pending->review($pending->id, $pending->version, Verdict::Comment, $ada->id, 'Thanks!');
        self::assertSame(ReviewState::Approved, $this->repository->get($pending->id)->state, 'Comments don\'t change the state.');

        // Changes after approval need approving again.
        $edited = $this->pending->update(new UpdatePendingRevision($pending->id, $pending->version)->excerpt('Our hours'));
        self::assertSame(ReviewState::InReview, $edited->state);
        $this->pending->review($edited->id, $edited->version, Verdict::Approve, $grace->id);

        $reviews = $this->repository->reviews($pending->id);
        self::assertSame(
            [[Verdict::RequestChanges, $grace->id, 'Say which days.', 2], [Verdict::Approve, $grace->id, null, 3], [Verdict::Comment, $ada->id, 'Thanks!', 3], [Verdict::Approve, $grace->id, null, 4]],
            array_map(static fn (\Quire\Content\Revision\Review $review): array => [$review->verdict, $review->reviewerId, $review->message, $review->revisionVersion], $reviews),
        );

        $this->writer->publishPendingRevision($edited->id, $edited->version, $grace->id);
        $revision = $this->revisions($post)[0];
        self::assertSame(['Opening times, Monday to Friday', $ada->id, $grace->id], [$revision->title, $revision->authorId, $revision->approvedBy], 'The revision records who approved it.');
        self::assertEquals(0, $this->connection->fetchOne('SELECT count(*) FROM quire.content_revision_review'), 'Reviews go with the pending revision.');
    }

    public function testPreviewsRenderTheChanges(): void
    {
        $post = $this->published();
        $pending = $this->pending->create($post->id);
        $pending = $this->pending->update(new UpdatePendingRevision($pending->id, 1)->body('Pending *text*'));
        // As in production: with the cache.
        $renderer = new ContentRenderer(self::getContainer()->get(Formatter::class), new ArrayAdapter(), 'test', debug: false);

        $live = $this->content($post);
        self::assertStringContainsString('Opening hours', $renderer->body($live));

        $preview = $this->repository->preview($pending);
        self::assertSame([$live->id, $live->version, 'Pending *text*'], [$preview->id, $preview->version, $preview->body]);
        self::assertSame("<p>Pending <em>text</em></p>\n", $renderer->body($preview), 'Not the cached live text.');
        self::assertStringContainsString('Opening hours', $renderer->body($live), 'Nor the other way round.');

        $pending = $this->pending->update(new UpdatePendingRevision($pending->id, $pending->version)->body('Newer'));
        self::assertSame("<p>Newer</p>\n", $renderer->body($this->repository->preview($pending)), 'Each save previews anew.');
    }

    public function testStartingFromARevisionAndDiscarding(): void
    {
        $post = $this->published();
        $first = $this->revisions($post)[0];
        $this->writer->update(new UpdateContent($post->id, $post->version)->title('Changed'));

        $pending = $this->pending->create($post->id, fromRevision: $first->id);
        self::assertSame(['Changed', 'Hours'], [$pending->base->title, $pending->proposed->title], 'Restoring a revision, for review.');

        try {
            $this->pending->create($post->id, fromRevision: $this->published()->id);
            self::fail('Another item\'s revision.');
        } catch (InvalidContentException $e) {
            self::assertSame(['revision'], array_keys($e->violations));
        }

        $this->events = [];
        $this->pending->discard($pending->id);
        self::assertNull($this->repository->find($pending->id));
        self::assertInstanceOf(PendingRevisionDiscarded::class, $this->recorded()[0] ?? null);
        self::assertSame('Changed', $this->content($post)->title);

        $this->expectException(ContentNotFoundException::class);
        $this->writer->publishPendingRevision($pending->id, $pending->version);
    }

    public function testItemsInTheTrash(): void
    {
        $post = $this->published();
        $pending = $this->pending->create($post->id);
        $trashed = $this->writer->trash($post->id, $post->version);

        foreach ([
            fn (): PendingRevision => $this->pending->update(new UpdatePendingRevision($pending->id, 1)->title('X')),
            fn (): Content => $this->writer->publishPendingRevision($pending->id, 1),
            fn (): PendingRevision => $this->pending->create($post->id),
        ] as $operation) {
            try {
                $operation();
                self::fail('Changed an item in the trash.');
            } catch (InvalidContentException $e) {
                self::assertSame(['deleted_at'], array_keys($e->violations));
            }
        }

        $this->writer->purge($trashed->id);
        self::assertNull($this->repository->find($pending->id), 'Deleted with the item.');
    }

    public function testListsAnItemsPendingRevisions(): void
    {
        [$ada, $grace] = [$this->user('ada'), $this->user('grace')];
        $post = $this->published();
        $first = $this->pending->create($post->id, $ada->id);
        $second = $this->pending->create($post->id, $grace->id);
        $this->pending->update(new UpdatePendingRevision($first->id, 1)->title('Newest'));

        self::assertSame([$first->id, $second->id], array_map(static fn (PendingRevision $p): string => $p->id, $this->repository->forContent($post->id)), 'Most recently saved first.');
        self::assertSame([$second->id], array_map(static fn (PendingRevision $p): string => $p->id, $this->repository->forContent($post->id, $grace->id)));
        self::assertSame([], $this->repository->forContent('not-an-id'));
    }

    public function testChangesArePublishedAtTheirTime(): void
    {
        $ada = $this->user('ada');
        $post = $this->published();
        $pending = $this->pending->create($post->id, $ada->id);
        $pending = $this->pending->update(new UpdatePendingRevision($pending->id, $pending->version)->title('Opening times'));

        $later = $this->pending->schedule($pending->id, $pending->version, new \DateTimeImmutable('+1 hour', new \DateTimeZone('Europe/Berlin')), $ada->id);
        self::assertSame($ada->id, $later->scheduledBy);
        self::assertEqualsWithDelta(time() + 3600, $later->publishAt?->getTimestamp(), 5, 'The same instant, whatever its time zone.');
        self::assertSame([], $this->writer->publishDue(), 'Not yet.');
        self::assertSame('Hours', $this->content($post)->title);

        $due = $this->pending->schedule($later->id, $later->version, new \DateTimeImmutable('-1 minute'), $ada->id);
        $published = $this->writer->publishDue();

        self::assertSame(['Opening times'], array_map(static fn (Content $item): string => $item->title, $published));
        self::assertSame('Opening times', $this->content($post)->title);
        self::assertNull($this->repository->find($due->id));
        self::assertSame($ada->id, $this->revisions($post)[0]->authorId, 'Credited to the changes\' author.');
    }

    public function testChangesThatCantBePublishedAtTheirTimeStayPendingWithTheReason(): void
    {
        $failures = [];
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(PendingRevisionPublishFailed::class, static function (PendingRevisionPublishFailed $event) use (&$failures): void {
            $failures[] = $event;
        });

        $post = $this->published();
        $pending = $this->pending->create($post->id);
        $pending = $this->pending->update(new UpdatePendingRevision($pending->id, 1)->body(str_replace('9 to 5', '9 to 6', self::BODY)));
        $pending = $this->pending->schedule($pending->id, $pending->version, new \DateTimeImmutable('-1 minute'));
        // Someone changes the same line meanwhile.
        $this->writer->update(new UpdateContent($post->id, $post->version)->body(str_replace('9 to 5', '10 to 5', self::BODY)));

        self::assertSame([], $this->writer->publishDue());

        $pending = $this->repository->get($pending->id);
        self::assertNull($pending->publishAt, 'Not tried again every minute.');
        self::assertSame('Changes published since conflict with these: body. Resolve them, then publish or schedule the changes again.', $pending->publishProblem);
        self::assertCount(1, $failures);
        self::assertSame($pending->publishProblem, $failures[0]->reason);

        // Scheduling again forgets the problem.
        $pending = $this->pending->schedule($pending->id, $pending->version, new \DateTimeImmutable('+1 day'));
        self::assertNull($pending->publishProblem);
    }

    public function testScheduledChangesNeedTheirApprovalWhenTheSiteAsksForIt(): void
    {
        self::getContainer()->get(Settings::class)->set(ContentWriter::APPROVAL_SETTING, true);
        $post = $this->published();
        $pending = $this->pending->create($post->id);
        $pending = $this->pending->update(new UpdatePendingRevision($pending->id, 1)->title('Unapproved'));
        $pending = $this->pending->schedule($pending->id, $pending->version, new \DateTimeImmutable('-1 minute'));

        $this->writer->publishDue();

        self::assertSame('Hours', $this->content($post)->title);
        self::assertSame('The changes need to be approved before they\'re published.', $this->repository->get($pending->id)->publishProblem);
    }

    public function testListsWhatWaitsForReviewAndAnAuthorsChanges(): void
    {
        [$ada, $grace] = [$this->user('ada'), $this->user('grace')];
        $post = $this->published();
        $waiting = $this->pending->create($post->id, $ada->id);
        $waiting = $this->pending->submit($waiting->id, $waiting->version);
        $writing = $this->pending->create($post->id, $grace->id);
        $trashed = $this->published();
        $hidden = $this->pending->create($trashed->id, $ada->id);
        $this->pending->submit($hidden->id, $hidden->version);
        $this->writer->trash($trashed->id, $this->content($trashed)->version);

        self::assertSame([$waiting->id], array_map(static fn (PendingRevision $p): string => $p->id, $this->repository->awaitingReview()), 'Not those being written, nor of items in the trash.');
        self::assertSame([$waiting->id], array_map(static fn (PendingRevision $p): string => $p->id, $this->repository->byAuthor($ada->id)));
        self::assertSame([$writing->id], array_map(static fn (PendingRevision $p): string => $p->id, $this->repository->byAuthor($grace->id)));
        self::assertSame([], $this->repository->byAuthor('not-an-id'));
    }

    /**
     * @return list<object>
     */
    private function recorded(): array
    {
        return $this->events;
    }

    private function published(): Content
    {
        $post = $this->writer->create(new CreateContent('post', 'Hours', body: self::BODY));

        return $this->writer->transition($post->id, $post->version, Status::PUBLISH);
    }

    private function content(Content $content): Content
    {
        return self::getContainer()->get(ContentRepository::class)->get($content->id);
    }

    /**
     * @return list<\Quire\Content\Revision\Revision>
     */
    private function revisions(Content $content): array
    {
        return self::getContainer()->get(RevisionRepository::class)->forContent($content->id);
    }

    private function user(string $name): User
    {
        return self::getContainer()->get(UserWriter::class)->create(new CreateUser(ucfirst($name), $name.'@example.com', roles: ['editor']));
    }
}
