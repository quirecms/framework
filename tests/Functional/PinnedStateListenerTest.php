<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Quire's migrations record what a pinned site runs (this repository's tests pin extensions), so the site
 * keeps it when it stops pinning. Carrying it over is in {@see \App\Tests\Integration\PinnedStateTest}.
 */
final class PinnedStateListenerTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement('DELETE FROM quire.pinned_state');
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('DELETE FROM quire.pinned_state');
        parent::tearDown();
    }

    public function testMigratingRecordsThePinnedExtensionsAndTheme(): void
    {
        self::assertSame(0, $this->console(['command' => 'quire:migrations:migrate', '--no-interaction' => true, '--allow-no-migration' => true]));

        self::assertSame(
            ['enabled' => '["quire-admin", "quire-fixture-hello"]', 'theme' => null],
            $this->connection->fetchAssociative('SELECT enabled, theme FROM quire.pinned_state'),
        );
    }

    public function testADryRunAndOtherCommandsRecordNothing(): void
    {
        $this->console(['command' => 'quire:migrations:migrate', '--no-interaction' => true, '--allow-no-migration' => true, '--dry-run' => true]);
        $this->console(['command' => 'quire:migrations:status']);

        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM quire.pinned_state'));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(array $input): int
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('No kernel.'));
        $application->setAutoExit(false);

        return new ApplicationTester($application)->run($input, ['interactive' => false]);
    }
}
