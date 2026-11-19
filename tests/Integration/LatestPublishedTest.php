<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\Content;
use Quire\Content\ContentWriter;
use Quire\Content\Query\LatestPublished;
use Quire\Content\Status;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The cached latest items, for pages bots hit most: never more than visitors see, and never a title
 * just taken down.
 */
final class LatestPublishedTest extends KernelTestCase
{
    private Connection $connection;

    private ContentWriter $writer;

    private LatestPublished $latest;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->writer = self::getContainer()->get(ContentWriter::class);
        $this->latest = self::getContainer()->get(LatestPublished::class);
        $this->latest->forget();
    }

    protected function tearDown(): void
    {
        $this->latest->forget();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testTheNewestVisibleItemsKeptUntilOneChanges(): void
    {
        $this->published('Older', '-2 hours');
        $newer = $this->published('Newer', '-1 hour');
        $this->writer->create(new CreateContent('post', 'Draft'));

        self::assertSame(['Newer', 'Older'], $this->titles($this->latest->items(5)), 'Newest first, no draft.');
        self::assertSame(['Newer'], $this->titles($this->latest->items(1)));

        $this->connection->executeStatement("UPDATE quire.content SET title = 'Changed behind its back' WHERE id = ?", [$newer->id]);
        self::assertSame(['Newer', 'Older'], $this->titles($this->latest->items(5)), 'Kept: it isn\'t queried each time.');

        $current = $this->writer->transition($newer->id, $newer->version, Status::UNPUBLISH);
        self::assertSame(['Older'], $this->titles($this->latest->items(5)), 'Unpublished: gone at once, not after the cache\'s lifetime.');
        self::assertSame(Status::DRAFT, $current->status);
    }

    private function published(string $title, string $at): Content
    {
        $item = $this->writer->create(new CreateContent('post', $title));

        return $this->writer->transition($item->id, $item->version, Status::PUBLISH, new \DateTimeImmutable($at));
    }

    /**
     * @param list<Content> $items
     *
     * @return list<string>
     */
    private function titles(array $items): array
    {
        return array_map(static fn (Content $item): string => $item->title, $items);
    }
}
