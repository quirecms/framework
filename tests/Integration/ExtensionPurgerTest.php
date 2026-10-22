<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use Quire\Content\Command\CreateContent;
use Quire\Content\ContentWriter;
use Quire\Database\MigrationsFactory;
use Quire\Extension\Event\ExtensionPurging;
use Quire\Extension\ExtensionPurger;
use Quire\Extension\InstalledExtensions;
use Quire\Settings\Settings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Purges the fixture extension for real, then restores it, so later tests find it intact.
 */
final class ExtensionPurgerTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('DELETE FROM quire.setting');
        $this->connection->executeStatement('DELETE FROM quire.content');

        // Executed migration objects are frozen, so restore the schema from a fresh kernel.
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->migrateToLatest();

        parent::tearDown();
    }

    public function testMigrationsRunInOrderCoreFirst(): void
    {
        $versions = array_map(
            static fn (\Doctrine\Migrations\Metadata\AvailableMigration $migration): string => (string) $migration->getVersion(),
            // The plan calculator applies the configured order; the repository itself isn't sorted.
            $this->migrations()->getMigrationPlanCalculator()->getMigrations()->getItems(),
        );

        self::assertSame([
            \Quire\Migrations\Version20261021120000::class,
            \Quire\Migrations\Version20261022090000::class,
            // Core's migrations run first, even when an extension's is older.
            \Quire\Migrations\Version20261022120000::class,
            \Quire\Migrations\Version20261022180000::class,
            \Quire\Fixtures\Hello\Migrations\Version20261022100000::class,
            \Quire\Fixtures\Hello\Migrations\Version20261022150000::class,
        ], $versions);
    }

    public function testEnabledExtensionsAreMigrated(): void
    {
        self::assertTrue($this->tableExists('quire.quire_fixture_hello_visit'));
    }

    public function testPurgeRollsBackMigrationsAndDeletesSettingsAndFields(): void
    {
        $container = self::getContainer();
        $container->get(Settings::class)->set('quire-fixture-hello.greeting', 'Howdy');
        $container->get(Settings::class)->set('site.title', 'Untouched');
        $post = $container->get(ContentWriter::class)->create(new CreateContent('post', 'Hello', fields: ['mood' => 'happy', 'rating' => 3]));
        // A value no enabled extension declares, e.g. from an extension removed earlier: not this one's to delete.
        $this->connection->executeStatement('UPDATE quire.content SET fields = fields || \'{"other": 1}\' WHERE id = ?', [$post->id]);

        $purging = [];
        $container->get(EventDispatcherInterface::class)->addListener(ExtensionPurging::class, static function (ExtensionPurging $event) use (&$purging): void {
            $purging[] = $event->extension->id;
        });

        $output = [];
        $container->get(ExtensionPurger::class)->purge(
            [$container->get(InstalledExtensions::class)->get('quire-fixture-hello')],
            static function (string $line) use (&$output): void {
                $output[] = $line;
            },
        );

        self::assertSame(['quire-fixture-hello'], $purging);
        self::assertFalse($this->tableExists('quire.quire_fixture_hello_visit'));
        self::assertFalse($this->tableExists('quire.quire_fixture_hello_like'));
        self::assertFalse($this->migrations()->getMetadataStorage()->getExecutedMigrations()->hasMigration(new Version(\Quire\Fixtures\Hello\Migrations\Version20261022100000::class)));
        self::assertEquals(['site.title'], $this->connection->fetchFirstColumn('SELECT name FROM quire.setting'));
        self::assertTrue($this->tableExists('quire.setting'), 'Core migrations are untouched.');
        self::assertSame('{"other": 1}', $this->connection->fetchOne('SELECT fields::text FROM quire.content WHERE id = ?', [$post->id]));
        self::assertSame('{}', $this->connection->fetchOne('SELECT fields::text FROM quire.content_revision WHERE content_id = ?', [$post->id]), 'Revisions too.');
        self::assertSame([
            'Purging "quire-fixture-hello"…',
            '  Rolled back 2 migration(s).',
            '  Deleted 1 stored setting(s).',
            '  Deleted the fields mood, rating, score, featured, subtitle, color, size, tags, due, event_at, extra from 1 post.',
            '  Deleted the fields recipient from 0 greeting cards.',
        ], $output);
    }

    private function tableExists(string $table): bool
    {
        return null !== $this->connection->fetchOne('SELECT to_regclass(?)', [$table]);
    }

    private function migrations(): DependencyFactory
    {
        return self::getContainer()->get(MigrationsFactory::SERVICE);
    }

    private function migrateToLatest(): void
    {
        $migrations = $this->migrations();
        $migrations->getMetadataStorage()->ensureInitialized();
        $latest = $migrations->getVersionAliasResolver()->resolveVersionAlias('latest');
        $plan = $migrations->getMigrationPlanCalculator()->getPlanUntilVersion($latest);
        $migrations->getMigrator()->migrate($plan, new MigratorConfiguration());
    }
}
