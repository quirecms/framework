<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Quire\Runtime\ServerContract;
use Quire\Runtime\SiteReport;
use Quire\User\CreateUser;
use Quire\User\Role;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * "quire:about": what tools that manage sites (Quire Server) read about a site.
 */
final class AboutCommandTest extends KernelTestCase
{
    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testTheReportHasWhatAServerReliesOn(): void
    {
        $report = $this->report();

        self::assertSame(ServerContract::VERSION, $report['contract']);
        self::assertSame(\PHP_VERSION, $report['php']);
        self::assertSame('test', $report['environment']);
        self::assertNotSame('unknown', $report['quire']['version']);
        self::assertSame(0, $report['migrations']['pending']);
        self::assertGreaterThan(0, $report['migrations']['executed']);
        self::assertSame('ok', $report['health']['status']);
        self::assertSame(['cache', 'database', 'extensions'], array_keys($report['health']['checks']));
        self::assertNotNull($report['site']['title']);
        self::assertFalse($report['site']['installed'], 'No admin yet.');

        $admin = array_values(array_filter($report['extensions'], static fn (array $extension): bool => 'quire-admin' === $extension['id']));
        self::assertCount(1, $admin);
        self::assertSame(['package' => 'quire/admin', 'type' => 'extension'], ['package' => $admin[0]['package'], 'type' => $admin[0]['type']]);

        self::getContainer()->get(UserWriter::class)->create(new CreateUser(displayName: 'Ada', email: 'ada@example.com', password: 'correct horse battery', roles: [Role::ADMIN]));
        self::assertTrue($this->report()['site']['installed'], 'Installed once there\'s an admin.');
    }

    public function testTheJsonIsTheReport(): void
    {
        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['--format' => 'json']));

        self::assertSame(
            json_decode(json_encode($this->report(), \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR),
            json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function testTheTableIsForPeople(): void
    {
        $tester = $this->tester();

        self::assertSame(0, $tester->execute([]));
        $output = $tester->getDisplay();
        self::assertStringContainsString('Server contract', $output);
        self::assertStringContainsString('Healthy.', $output);

        $this->expectExceptionMessageIsOrContains('Unknown format "xml". Use "table" or "json".');
        $tester->execute(['--format' => 'xml']);
    }

    /**
     * @return array{contract: int, quire: array{version: string, reference: ?string}, php: string, symfony: string, environment: string, site: array{title: ?string, locale: ?string, timezone: ?string, url: ?string, installed: ?bool}, extensions: list<array{id: string, package: string, version: string, type: string, status: string}>, theme: ?string, migrations: array{executed: ?int, pending: ?int}, health: array{status: string, checks: array<string, array{status: string, message?: string}>}}
     */
    private function report(): array
    {
        return self::getContainer()->get(SiteReport::class)->report();
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new Application(self::$kernel ?? throw new \LogicException('No kernel.'))->find('quire:about'));
    }
}
