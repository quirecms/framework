<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Content\Field\FieldExpression;
use Quire\Content\Field\FieldIndexes;
use Quire\Content\Type\ContentTypeRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs outside a transaction: indexes are built concurrently, which PostgreSQL doesn't allow inside one.
 */
final class FieldIndexesTest extends KernelTestCase
{
    private Connection $connection;

    private FieldIndexes $indexes;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->indexes = self::getContainer()->get(FieldIndexes::class);
        $this->dropManagedIndexes();
    }

    protected function tearDown(): void
    {
        $this->dropManagedIndexes();
        parent::tearDown();
    }

    public function testCreatesAnIndexPerIndexedField(): void
    {
        $lines = [];
        $result = $this->indexes->sync(output: static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $fields = array_map(static fn (array $index): string => $index['type'].'.'.$index['field'], array_values($this->indexes->desired()));
        self::assertSame(['post.rating', 'post.featured', 'post.tags', 'post.event_at', 'post.accent', 'greeting_card.recipient'], $fields);
        self::assertCount(6, $result['created']);
        self::assertSame([], $result['dropped']);
        self::assertStringStartsWith('Creating content_field_post_rating_', $lines[0]);
        $existing = $this->indexes->existing();
        ksort($existing);
        $expected = array_fill_keys(array_keys($this->indexes->desired()), true);
        ksort($expected);
        self::assertSame($expected, $existing);

        self::assertSame(['created' => [], 'dropped' => []], $this->indexes->sync(), 'Nothing left to do.');
    }

    public function testDropsIndexesNoLongerDeclaredAndRebuildsInvalidOnes(): void
    {
        $this->indexes->sync();
        $this->connection->executeStatement("CREATE INDEX content_field_post_removed_00000000 ON quire.content ((fields ->> 'removed'))");
        $rating = array_key_first($this->indexes->desired());
        self::assertIsString($rating);
        // What a failed concurrent build leaves behind.
        $this->connection->executeStatement('UPDATE pg_index SET indisvalid = false WHERE indexrelid = CAST(? AS regclass)', ['quire.'.$rating]);

        $result = $this->indexes->sync();

        self::assertSame([$rating, 'content_field_post_removed_00000000'], $result['dropped']);
        self::assertSame([$rating], $result['created']);
        self::assertTrue($this->indexes->existing()[$rating]);
    }

    public function testDryRunChangesNothing(): void
    {
        $result = $this->indexes->sync(dryRun: true);

        self::assertCount(6, $result['created']);
        self::assertSame([], $this->indexes->existing());
    }

    public function testQueriesUsingTheFieldExpressionUseTheIndex(): void
    {
        $this->indexes->sync();
        $rating = self::getContainer()->get(ContentTypeRegistry::class)->get('post')->field('rating');
        self::assertNotNull($rating);

        $this->connection->beginTransaction();

        try {
            // The test table is tiny, so tell the planner to avoid sequential scans when it can.
            $this->connection->executeStatement('SET LOCAL enable_seqscan = off');
            /** @var list<string> $lines */
            $lines = $this->connection->fetchFirstColumn(\sprintf(
                "EXPLAIN SELECT id FROM quire.content WHERE type = 'post' AND %1\$s >= 3 ORDER BY %1\$s DESC LIMIT 10",
                FieldExpression::value($rating),
            ));
            $plan = implode("\n", $lines);
        } finally {
            $this->connection->rollBack();
        }

        self::assertStringContainsString('content_field_post_rating_', $plan);
    }

    private function dropManagedIndexes(): void
    {
        foreach (array_keys($this->indexes->existing()) as $name) {
            $this->connection->executeStatement(\sprintf('DROP INDEX IF EXISTS quire.%s', $name));
        }
    }
}
