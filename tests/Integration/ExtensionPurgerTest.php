<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
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
        $this->connection->executeStatement('DELETE FROM setting');

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
            'Quire\Migrations\Version20261021120000',
            'Quire\Migrations\Version20261022090000',
            \Quire\Fixtures\Hello\Migrations\Version20261022100000::class,
        ], $versions);
    }

    public function testEnabledExtensionsAreMigrated(): void
    {
        self::assertTrue($this->tableExists('quire_fixture_hello_visit'));
    }

    public function testPurgeRollsBackMigrationsAndDeletesSettings(): void
    {
        $container = self::getContainer();
        $container->get(Settings::class)->set('quire-fixture-hello.greeting', 'Howdy');
        $container->get(Settings::class)->set('site.title', 'Untouched');

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
        self::assertFalse($this->tableExists('quire_fixture_hello_visit'));
        self::assertFalse($this->migrations()->getMetadataStorage()->getExecutedMigrations()->hasMigration(new Version(\Quire\Fixtures\Hello\Migrations\Version20261022100000::class)));
        self::assertEquals(['site.title'], $this->connection->fetchFirstColumn('SELECT name FROM setting'));
        self::assertTrue($this->tableExists('setting'), 'Core migrations are untouched.');
        self::assertSame(['Purging "quire-fixture-hello"…', '  Rolled back 1 migration(s).', '  Deleted 1 stored setting(s).'], $output);
    }

    private function tableExists(string $table): bool
    {
        return $this->connection->createSchemaManager()->tablesExist([$table]);
    }

    private function migrations(): DependencyFactory
    {
        return self::getContainer()->get('doctrine.migrations.dependency_factory');
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
