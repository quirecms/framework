<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\Command\UpdateContent;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Exception\InvalidContentException;
use Quire\Content\Query\ContentQuery;
use Quire\Content\Query\Exception\InvalidQueryException;
use Quire\Content\Status;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Relation fields of the fixture extension: greeting cards link to posts ("posts", inverse "cards") and
 * to another card ("reply_to"); pages link to pages and posts ("related").
 */
final class RelationFieldsTest extends KernelTestCase
{
    private Connection $connection;

    private ContentWriter $writer;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->writer = self::getContainer()->get(ContentWriter::class);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testLinksAreCheckedAndKeptInOrder(): void
    {
        [$first, $second] = [$this->post('First'), $this->post('Second')];
        $card = $this->card('Ada', posts: [$second, $first]);

        self::assertSame([$second->id, $first->id], $card->field('posts'), 'Items become their IDs, in order.');
        self::assertSame([[$second->id, 0], [$first->id, 1]], $this->links($card, 'posts'));

        $other = $this->card('Grace');
        $trashed = $this->writer->trash($this->post('Gone')->id, 1);

        foreach ([
            'No content item has the ID "0190a3c4-0000-7000-8000-000000000000".' => ['0190a3c4-0000-7000-8000-000000000000'],
            \sprintf('The item "%s" is in the trash.', $trashed->id) => [$trashed->id],
            \sprintf('Only items of the type "post" can be linked here; "%s" is a greeting_card.', $other->id) => [$other->id],
            'Each item can be linked once.' => [$first->id, $first->id],
            'At most 3 items.' => [$first->id, $second->id, $this->post('Third')->id, $this->post('Fourth')->id],
            'Expected a list of IDs of content items.' => ['first'],
        ] as $message => $posts) {
            $this->assertViolation('fields.posts', $message, fn (): Content => $this->card('Wrong', posts: $posts));
        }

        $this->assertViolation('fields.reply_to', 'An item can\'t be linked to itself.', fn (): Content => $this->writer->update(new UpdateContent($card->id, $card->version)->field('reply_to', $card->id)));

        $updated = $this->writer->update(new UpdateContent($card->id, $card->version)->field('posts', [$first])->field('reply_to', $other));
        self::assertSame([[[$first->id, 0]], [[$other->id, 0]]], [$this->links($updated, 'posts'), $this->links($updated, 'reply_to')]);

        $cleared = $this->writer->update(new UpdateContent($card->id, $updated->version)->field('posts', null));
        self::assertSame([[], null], [$this->links($cleared, 'posts'), $cleared->field('posts')]);
    }

    public function testQueriesFilterByLinks(): void
    {
        [$first, $second] = [$this->post('First'), $this->post('Second')];
        $both = $this->card('Both', posts: [$first, $second]);
        $one = $this->card('One', posts: [$first]);
        $reply = $this->card('Reply', replyTo: $one);
        $cards = self::getContainer()->get(ContentRepository::class)->query('greeting_card')->withoutVisibilityScope()->orderBy('title');

        self::assertSame(['Both', 'One'], $this->titles($cards->where('fields.posts', 'contains', $first)));
        self::assertSame(['Both'], $this->titles($cards->where('fields.posts', 'contains', [$first->id, $second->id])));
        self::assertSame(['Both'], $this->titles($cards->where('fields.posts', 'contains any', [$second, 'not-an-id'])));
        self::assertSame([], $this->titles($cards->where('fields.posts', 'contains', 'not-an-id')), 'An ID that isn\'t one matches nothing.');
        self::assertSame(['Reply'], $this->titles($cards->where('fields.posts', '=', null)));
        self::assertSame(['Reply'], $this->titles($cards->where('fields.reply_to', '=', $one)));
        self::assertSame(['Both', 'One'], $this->titles($cards->where('fields.reply_to', '!=', $one->id)));
        self::assertSame(['Reply'], $this->titles($cards->where('fields.reply_to', 'in', [$one->id, $both->id])));
        self::assertSame(['Reply'], $this->titles($cards->where('fields.reply_to', '!=', null)));

        foreach ([
            'links to a list of items: filter it with "contains"' => static fn (): ContentQuery => $cards->where('fields.posts', '=', $first),
            'links to one item: filter it with "="' => static fn (): ContentQuery => $cards->where('fields.reply_to', 'contains', $one),
            'can\'t be sorted by "fields.posts"' => static fn (): ContentQuery => $cards->orderBy('fields.posts'),
        ] as $message => $query) {
            try {
                $query()->get();
                self::fail('The query was accepted: '.$message);
            } catch (InvalidQueryException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }

        self::assertSame($reply->id, $cards->where('fields.reply_to', '=', $one)->first()?->id);
    }

    public function testRelatedItemsLoadWithTheQuery(): void
    {
        [$first, $second] = [$this->post('First', publish: true), $this->post('Second', publish: true)];
        $draft = $this->post('Draft');
        $hidden = $this->card('nobody', posts: [$first]);
        $card = $this->card('Ada', posts: [$second, $draft, $first], replyTo: $hidden);
        $card = $this->writer->transition($card->id, $card->version, Status::PUBLISH);
        $repository = self::getContainer()->get(ContentRepository::class);

        $loaded = $repository->query('greeting_card')->withoutVisibilityScope()->where('id', '=', $card->id)->with('posts', 'reply_to')->first();
        self::assertSame(['Second', 'Draft', 'First'], $this->titles($this->items($loaded?->attribute('posts'))));
        $replyTo = $loaded?->attribute('reply_to');
        self::assertSame('nobody', $replyTo instanceof Content ? $replyTo->title : null);

        // With the visibility rules, links to items the viewer can't see are left out.
        $visible = $repository->query('greeting_card')->published()->withoutVisibilityScope()->where('id', '=', $card->id)->with('posts')->first();
        self::assertSame(['Second', 'First'], $this->titles($this->items($visible?->attribute('posts'))), 'Not the draft.');
        $card2 = $repository->query('greeting_card')->where('id', '=', $card->id)->with('reply_to')->first();
        self::assertNull($card2?->attribute('reply_to'), 'Not the card the fixture\'s visibility rule hides.');

        // The inverse: the cards that link to each post.
        $posts = $repository->query('post')->withoutVisibilityScope()->where('id', 'in', [$first->id, $second->id])->orderBy('title')->with('cards')->get();
        self::assertSame([['Ada', 'nobody'], ['Ada']], array_map(fn (Content $post): array => $this->titles($this->items($post->attribute('cards'))), $posts));

        $this->expectException(InvalidQueryException::class);
        $repository->query('page')->with('cards');
    }

    public function testDeletingAnItemForGoodRemovesTheLinksToIt(): void
    {
        [$first, $second] = [$this->post('First'), $this->post('Second')];
        $card = $this->card('Ada', posts: [$first, $second]);
        $reply = $this->card('Reply', replyTo: $card);

        $this->writer->purge($this->writer->trash($first->id, 1)->id);
        self::assertSame([$second->id], $this->get($card)->field('posts'), 'Gone from the list…');
        self::assertSame([[$second->id, 1]], $this->links($card, 'posts'), '…and from the table.');

        $this->writer->purge($this->writer->trash($card->id, $this->get($card)->version)->id);
        self::assertNull($this->get($reply)->field('reply_to'), 'A single link goes from the fields too.');
        self::assertSame([], $this->links($reply, 'reply_to'));
    }

    public function testRestoringARevisionDropsLinksToItemsGoneSince(): void
    {
        [$kept, $gone] = [$this->post('Kept'), $this->post('Gone')];
        $page = $this->writer->create(new CreateContent('page', 'About', fields: ['related' => [$kept, $gone]]));
        $changed = $this->writer->update(new UpdateContent($page->id, $page->version)->field('related', [$kept]));
        $this->writer->purge($this->writer->trash($gone->id, 1)->id);

        $revisions = self::getContainer()->get(\Quire\Content\Revision\RevisionRepository::class)->forContent($page->id);
        $first = end($revisions);
        self::assertInstanceOf(\Quire\Content\Revision\Revision::class, $first);
        $restored = $this->writer->restoreRevision($page->id, $first->id, $changed->version);

        self::assertSame([$kept->id], $restored->field('related'));
    }

    private function post(string $title, bool $publish = false): Content
    {
        $post = $this->writer->create(new CreateContent('post', $title));

        return $publish ? $this->writer->transition($post->id, $post->version, Status::PUBLISH) : $post;
    }

    /**
     * @param list<Content|string>|null $posts
     */
    private function card(string $recipient, ?array $posts = null, ?Content $replyTo = null): Content
    {
        return $this->writer->create(new CreateContent('greeting_card', $recipient, fields: array_filter(['recipient' => $recipient, 'posts' => $posts, 'reply_to' => $replyTo], static fn (mixed $value): bool => null !== $value)));
    }

    private function get(Content $content): Content
    {
        return self::getContainer()->get(ContentRepository::class)->get($content->id);
    }

    /**
     * @return list<array{string, int}>
     */
    private function links(Content $content, string $field): array
    {
        return array_map(static fn (array $row): array => [new \Quire\Content\Internal\Row($row)->string('target_id'), new \Quire\Content\Internal\Row($row)->int('position')], $this->connection->fetchAllAssociative('SELECT target_id, position FROM quire.content_relation WHERE source_id = ? AND field = ? ORDER BY position', [$content->id, $field]));
    }

    /**
     * @param iterable<Content>|ContentQuery $items
     *
     * @return list<string>
     */
    private function titles(iterable $items): array
    {
        $titles = [];

        foreach ($items instanceof ContentQuery ? $items->get() : $items as $item) {
            $titles[] = $item->title;
        }

        return $titles;
    }

    /**
     * @return list<Content>
     */
    private function items(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values(array_filter($value, static fn (mixed $item): bool => $item instanceof Content));
    }

    private function assertViolation(string $property, string $message, \Closure $write): void
    {
        try {
            $write();
            self::fail(\sprintf('Saved, though "%s" should have been refused.', $message));
        } catch (InvalidContentException $e) {
            self::assertSame($message, $e->violations[$property] ?? null, json_encode($e->violations, \JSON_THROW_ON_ERROR));
        }
    }
}
