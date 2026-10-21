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
use Quire\Content\Revision\RevisionRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The fields the fixture extension declares (see tests/Fixtures/extensions/hello/extend.php).
 */
final class ContentFieldsTest extends KernelTestCase
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

    public function testValuesAreStoredInTheirTypes(): void
    {
        $post = $this->writer->create(new CreateContent('post', 'Hello', fields: [
            'mood' => 'happy',
            'rating' => 5,
            'score' => 4,
            'tags' => ['php', 'cms'],
            'due' => new \DateTimeImmutable('2026-12-24 15:00'),
            'event_at' => new \DateTimeImmutable('2026-12-24 18:00', new \DateTimeZone('Europe/Berlin')),
            'extra' => ['nested' => [1, 2]],
        ]));

        $stored = $this->get($post);
        self::assertSame(5, $stored->field('rating'));
        self::assertSame(4.0, $stored->field('score'), 'A whole number in a float field stays a float.');
        self::assertSame(['php', 'cms'], $stored->field('tags'));
        self::assertSame('2026-12-24', $stored->field('due'));
        self::assertSame('2026-12-24T17:00:00+00:00', $stored->field('event_at'), 'Stored in UTC, so it sorts as text.');
        self::assertSame(['nested' => [1, 2]], $stored->field('extra'));
        self::assertFalse($stored->field('featured'), 'The declared default.');
    }

    public function testDateTimesNeedAnOffset(): void
    {
        $post = $this->writer->create(new CreateContent('post', 'Hello', fields: ['event_at' => '2026-12-24T18:00:00+01:00']));

        self::assertSame('2026-12-24T17:00:00+00:00', $post->field('event_at'));
        self::assertSame(
            ['fields.event_at' => 'Expected a date and time such as "2026-12-24T18:00:00+01:00".'],
            $this->violations(fn (): Content => $this->writer->create(new CreateContent('post', 'Hello', fields: ['event_at' => '2026-12-24 18:00']))),
        );
    }

    public function testInvalidValues(): void
    {
        $violations = $this->violations(fn (): Content => $this->writer->create(new CreateContent('post', 'Hello', fields: [
            'mood' => 'angry',
            'rating' => 6,
            'score' => -1,
            'featured' => 'yes',
            'subtitle' => str_repeat('x', 101),
            'tags' => ['php', 3],
            'due' => '2026-02-30',
            'nope' => 1,
        ])));

        self::assertSame([
            'fields.mood' => 'Expected one of: "happy", "sad".',
            'fields.rating' => 'At most 5.',
            'fields.score' => 'At least 0.',
            'fields.featured' => 'Expected true or false.',
            'fields.subtitle' => 'At most 100 characters.',
            'fields.tags' => 'Expected a list of texts.',
            'fields.due' => 'Expected a date such as "2026-12-24".',
            'fields.nope' => 'Posts have no field "nope". Their fields are: mood, rating, score, featured, subtitle, color, size, tags, due, event_at, extra.',
        ], $violations);
    }

    public function testRequiredFields(): void
    {
        self::assertSame(['fields.recipient' => 'A value is required.'], $this->violations(fn (): Content => $this->writer->create(new CreateContent('greeting_card', 'Card'))));

        $card = $this->writer->create(new CreateContent('greeting_card', 'Card', fields: ['recipient' => 'Ada']));

        self::assertSame(['fields.recipient' => 'A value is required, so it can\'t be removed.'], $this->violations(fn (): Content => $this->writer->update(new UpdateContent($card->id, 1)->field('recipient', null))));
    }

    public function testValuesNoLongerDeclaredAreKept(): void
    {
        $post = $this->writer->create(new CreateContent('post', 'Hello', fields: ['mood' => 'happy']));
        // Left behind by an extension that was disabled.
        $this->connection->executeStatement('UPDATE content SET fields = fields || \'{"legacy": true}\' WHERE id = ?', [$post->id]);

        $updated = $this->writer->update(new UpdateContent($post->id, 1)->title('Changed')->field('mood', 'sad'));

        self::assertTrue($updated->field('legacy'), 'Saving other changes still works and keeps the value.');
        self::assertSame(['fields.legacy' => 'Posts have no field "legacy". Their fields are: mood, rating, score, featured, subtitle, color, size, tags, due, event_at, extra.'], $this->violations(fn (): Content => $this->writer->update(new UpdateContent($post->id, 2)->field('legacy', false))));
    }

    public function testRestoringARevisionKeepsValuesNoLongerDeclared(): void
    {
        $post = $this->writer->create(new CreateContent('post', 'One', fields: ['mood' => 'happy']));
        $post = $this->writer->update(new UpdateContent($post->id, 1)->title('Two')->field('mood', 'sad'));
        $this->connection->executeStatement('UPDATE content SET fields = fields || \'{"legacy": true}\' WHERE id = ?', [$post->id]);
        $this->connection->executeStatement('UPDATE content_revision SET fields = fields || \'{"old": 1}\' WHERE content_id = ?', [$post->id]);

        $first = self::getContainer()->get(RevisionRepository::class)->forContent($post->id)[1];
        $restored = $this->writer->restoreRevision($post->id, $first->id, 2);

        self::assertSame('happy', $restored->field('mood'));
        self::assertTrue($restored->field('legacy'));
        self::assertNull($restored->field('old'), 'Undeclared values in the revision aren\'t brought back.');
    }

    private function get(Content $content): Content
    {
        return self::getContainer()->get(ContentRepository::class)->get($content->id);
    }

    /**
     * @param \Closure(): mixed $operation
     *
     * @return array<string, string>
     */
    private function violations(\Closure $operation): array
    {
        try {
            $operation();
        } catch (InvalidContentException $e) {
            return $e->violations;
        }

        self::fail('The operation was accepted.');
    }
}
