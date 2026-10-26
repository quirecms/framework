<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Audit\AuditLog;
use Quire\Content\Command\CreateContent;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Testing\Database\QueryLog;
use Quire\User\CreateUser;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Finding many items, users or the latest audit entries at once, for pages that list them.
 */
final class FindManyTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testItemsAndUsersByTheirIdsInOneQuery(): void
    {
        $writer = self::getContainer()->get(ContentWriter::class);
        $draw = $writer->create(new CreateContent('post', 'Spring draw'));
        $venue = $writer->create(new CreateContent('post', 'Venue'));
        $users = self::getContainer()->get(UserWriter::class);
        $ada = $users->create(new CreateUser('Ada Lovelace', 'ada@example.org', 'ada'));
        // @phpstan-ignore symfonyContainer.serviceNotFound (registered in the test environment only)
        $log = self::getContainer()->get(QueryLog::class);
        \assert($log instanceof QueryLog);

        $log->start();
        $items = self::getContainer()->get(ContentRepository::class)->findMany([$draw->id, $venue->id, $draw->id, 'not-an-id', '01a17a4c-0000-7000-8000-000000000000']);
        $found = self::getContainer()->get(UserRepository::class)->findMany((static function () use ($ada): \Generator { yield $ada->id; })());
        self::assertCount(2, $log->stop(), 'One query each.');

        self::assertEqualsCanonicalizing([$draw->id, $venue->id], array_keys($items), 'By ID, without the unknown and duplicates.');
        self::assertSame('Venue', $items[$venue->id]->title);
        self::assertSame(['Ada Lovelace'], array_map(static fn ($user): string => $user->displayName, array_values($found)));
        self::assertSame([], self::getContainer()->get(UserRepository::class)->findMany([]));
    }

    public function testTheLatestEntryAboutEachSubject(): void
    {
        $log = self::getContainer()->get(AuditLog::class);
        $first = $log->record('content.transitioned', 'content', 'a', 'A');
        $latestA = $log->record('content.trashed', 'content', 'a', 'A');
        $log->record('user.signed_in', 'content', 'a', 'A');
        $onlyB = $log->record('content.trashed', 'content', 'b"\\', 'B');

        $latest = $log->latestAbout([['content', 'a'], ['content', 'b"\\'], ['content', 'c']], ['user.signed_in']);

        self::assertNotNull($first);
        self::assertSame(['content a' => $latestA?->id, 'content b"\\' => $onlyB?->id], $latest, 'Actions not counted are left out; quotes and backslashes are safe.');
    }
}
