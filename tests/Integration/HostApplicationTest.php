<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Quire\Content\Command\CreateContent;
use Quire\Content\ContentWriter;
use Quire\Database\MigrationsFactory;
use Quire\Settings\Settings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Quire shares the database with the application it's installed in, which may have tables of its own
 * with the same names (here: "content", "setting" and "extension" in the public schema).
 */
final class HostApplicationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();

        foreach (['content', 'setting', 'extension'] as $table) {
            $this->connection->executeStatement(\sprintf('CREATE TABLE public.%s (id SERIAL PRIMARY KEY, name TEXT)', $table));
        }
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testQuireUsesItsOwnTablesOnly(): void
    {
        self::getContainer()->get(Settings::class)->set('site.title', 'Tournament news');
        $post = self::getContainer()->get(ContentWriter::class)->create(new CreateContent('post', 'Final results'));

        foreach (['content', 'setting', 'extension'] as $table) {
            self::assertEquals(0, $this->connection->fetchOne(\sprintf('SELECT count(*) FROM public.%s', $table)), \sprintf('The application\'s "%s" table is untouched.', $table));
        }

        self::assertSame('Tournament news', self::getContainer()->get(Settings::class)->get('site.title'));
        self::assertEquals(1, $this->connection->fetchOne('SELECT count(*) FROM quire.content WHERE id = ?', [$post->id]));
    }

    public function testTheApplicationsSchemaToolsIgnoreQuiresTables(): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        // What "doctrine:schema:update" or "doctrine:migrations:diff" would run if the application's
        // mapping had no tables at all: it drops the application's tables, but none of Quire's.
        $sql = $this->connection->getDatabasePlatform()->getAlterSchemaSQL(
            $schemaManager->createComparator()->compareSchemas($schemaManager->introspectSchema(), new Schema()),
        );

        self::assertContains('DROP TABLE content', $sql);
        self::assertSame([], array_values(array_filter($sql, static fn (string $statement): bool => str_contains($statement, 'quire'))), implode("\n", $sql));
    }

    public function testQuiresMigrationsAreKeptApartFromTheApplications(): void
    {
        $versions = static fn (DependencyFactory $factory): array => array_map(
            static fn (AvailableMigration $migration): string => (string) $migration->getVersion(),
            $factory->getMigrationRepository()->getMigrations()->getItems(),
        );

        $application = self::getContainer()->get('doctrine.migrations.dependency_factory');
        $quire = self::getContainer()->get(MigrationsFactory::SERVICE);

        self::assertSame([], $versions($application), 'The application has no migrations of its own.');
        self::assertSame([
            \Quire\Migrations\Version20261021120000::class,
            \Quire\Migrations\Version20261022090000::class,
            \Quire\Migrations\Version20261022120000::class,
        ], \array_slice($versions($quire), 0, 3), 'Core first.');
        self::assertContains(\Quire\Fixtures\Hello\Migrations\Version20261022100000::class, $versions($quire));
    }
}
