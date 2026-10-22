<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The integrity rules the content tables enforce themselves, whoever writes to them.
 */
final class ContentSchemaTest extends KernelTestCase
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

    public function testIdsAreTimeOrderedUuids(): void
    {
        $first = $this->insert(['slug' => 'first']);
        $second = $this->insert(['slug' => 'second']);

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-/', $first, 'UUIDv7');
        self::assertLessThan($second, $first);
    }

    public function testSlugsAreUniqueAmongSiblingsOfTheSameTypeAndLocale(): void
    {
        $this->insert(['slug' => 'hello']);
        $this->insert(['slug' => 'hello', 'type' => 'page']);
        $this->insert(['slug' => 'hello', 'locale' => 'de']);
        $parent = $this->insert(['slug' => 'docs', 'type' => 'page']);
        $this->insert(['slug' => 'hello', 'type' => 'page', 'parent_id' => $parent]);

        $this->expectException(UniqueConstraintViolationException::class);
        // Top-level items have no parent; NULL parents still count as the same parent.
        $this->insert(['slug' => 'hello']);
    }

    public function testItemsInTheTrashFreeTheirSlug(): void
    {
        $this->insert(['slug' => 'hello', 'deleted_at' => '2026-10-01 10:00:00+00']);
        $this->insert(['slug' => 'hello']);

        self::assertEquals(2, $this->connection->fetchOne("SELECT COUNT(*) FROM quire.content WHERE slug = 'hello'"));
    }

    public function testATranslationGroupHasOneItemPerLocale(): void
    {
        $group = $this->insert(['slug' => 'hello']);
        $this->connection->executeStatement('UPDATE quire.content SET translation_group_id = id WHERE id = ?', [$group]);
        $this->insert(['slug' => 'hallo', 'locale' => 'de', 'translation_group_id' => $group]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->insert(['slug' => 'hallo-2', 'locale' => 'de', 'translation_group_id' => $group]);
    }

    /**
     * @param array<string, string> $values
     */
    #[DataProvider('invalidRows')]
    public function testRejectsInvalidRows(array $values): void
    {
        try {
            $this->insert($values);
        } catch (DriverException $e) {
            self::assertSame('23514', $e->getSQLState(), 'A check constraint rejected the row.');

            return;
        }

        self::fail('The row was accepted.');
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidRows(): iterable
    {
        yield 'empty slug' => [['slug' => '']];
        yield 'slug with a slash' => [['slug' => 'a/b']];
        yield 'published without a date' => [['slug' => 'a', 'status' => 'published']];
        yield 'scheduled without a date' => [['slug' => 'a', 'status' => 'scheduled']];
        yield 'fields not an object' => [['slug' => 'a', 'fields' => '[1, 2]']];
        yield 'version zero' => [['slug' => 'a', 'version' => '0']];
    }

    public function testExtensionStatusesNeedNoSchemaChange(): void
    {
        $this->insert(['slug' => 'a', 'status' => 'in_review']);

        self::assertSame('in_review', $this->connection->fetchOne("SELECT status FROM quire.content WHERE slug = 'a'"));
    }

    public function testTheSearchVectorFollowsTheRowAndItsLanguage(): void
    {
        $id = $this->insert(['slug' => 'dogs', 'title' => 'Running dogs', 'excerpt' => 'About dogs', 'body' => 'The dogs ran home.', 'search_config' => 'english']);

        self::assertTrue($this->searchMatches($id, 'run'), 'Stemmed with the English configuration.');
        self::assertSame("'dog':2A,4B,6C 'home':8C 'ran':7C 'run':1A", $this->connection->fetchOne('SELECT search::text FROM quire.content WHERE id = ?', [$id]));

        $this->connection->executeStatement("UPDATE quire.content SET body = 'Cats too.' WHERE id = ?", [$id]);

        self::assertTrue($this->searchMatches($id, 'cat'), 'Updated with the row: it can\'t go stale.');
        self::assertFalse($this->searchMatches($id, 'home'));
    }

    public function testRevisionsAreDeletedWithTheirContent(): void
    {
        $id = $this->insert(['slug' => 'a']);
        $this->connection->executeStatement(
            "INSERT INTO quire.content_revision (content_id, content_version, title, body, body_format, fields) VALUES (?, 1, 'A', '', 'markdown', '{}')",
            [$id],
        );

        $this->connection->executeStatement('DELETE FROM quire.content WHERE id = ?', [$id]);

        self::assertEquals(0, $this->connection->fetchOne('SELECT COUNT(*) FROM quire.content_revision'));
    }

    public function testEachVersionHasOneRevision(): void
    {
        $id = $this->insert(['slug' => 'a']);
        $insert = "INSERT INTO quire.content_revision (content_id, content_version, title, body, body_format, fields) VALUES (?, 1, 'A', '', 'markdown', '{}')";
        $this->connection->executeStatement($insert, [$id]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->executeStatement($insert, [$id]);
    }

    public function testParentsWithChildrenCantBeDeleted(): void
    {
        $parent = $this->insert(['slug' => 'docs', 'type' => 'page']);
        $this->insert(['slug' => 'intro', 'type' => 'page', 'parent_id' => $parent]);

        // Deleting a subtree is a decision (move the children, or delete them too), so it's never implicit.
        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->connection->executeStatement('DELETE FROM quire.content WHERE id = ?', [$parent]);
    }

    /**
     * @param array<string, string> $values
     *
     * @return string The new row's ID
     */
    private function insert(array $values): string
    {
        $row = $values + ['type' => 'post', 'status' => 'draft', 'locale' => 'en', 'title' => 'Hello', 'body_format' => 'markdown'];
        $columns = implode(', ', array_keys($row));
        $placeholders = implode(', ', array_fill(0, \count($row), '?'));

        $id = $this->connection->fetchOne(\sprintf('INSERT INTO quire.content (%s) VALUES (%s) RETURNING id', $columns, $placeholders), array_values($row));
        self::assertIsString($id);

        return $id;
    }

    private function searchMatches(string $id, string $word): bool
    {
        return true === $this->connection->fetchOne("SELECT search @@ to_tsquery('english', ?) FROM quire.content WHERE id = ?", [$word, $id]);
    }
}
