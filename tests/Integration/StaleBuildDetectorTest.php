<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Extension\Boot\CompileTimeState;
use Quire\Runtime\BackgroundRefresher;
use Quire\Runtime\StaleBuildDetector;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class StaleBuildDetectorTest extends KernelTestCase
{
    private Connection $connection;

    private int $now = 1_000;

    /** @var list<string> */
    private array $spawned = [];

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement('DELETE FROM quire.extension');
        $this->connection->executeStatement("INSERT INTO quire.extension (id, enabled, updated_at) VALUES ('acme-comments', true, now()), ('acme-seo', false, now())");
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('DELETE FROM quire.extension');
        parent::tearDown();
    }

    public function testNothingHappensWhileTheBuildMatches(): void
    {
        $this->request($this->detector(CompileTimeState::hashOf(['acme-comments'], null)));

        self::assertSame([], $this->spawned);
    }

    public function testAChangeElsewhereTriggersARefresh(): void
    {
        $detector = $this->detector(CompileTimeState::hashOf(['acme-comments'], null));
        $this->connection->executeStatement("UPDATE quire.extension SET enabled = true WHERE id = 'acme-seo'");

        $this->request($detector);

        self::assertCount(1, $this->spawned);
        self::assertStringContainsString('quire:app:refresh', $this->spawned[0]);
    }

    public function testAThemeChangeElsewhereTriggersARefresh(): void
    {
        $detector = $this->detector(CompileTimeState::hashOf(['acme-comments'], null));
        $this->connection->executeStatement("INSERT INTO quire.setting (name, value, updated_at) VALUES ('theme.active', '\"acme-dark\"', now())");

        try {
            $this->request($detector);
        } finally {
            $this->connection->executeStatement("DELETE FROM quire.setting WHERE name = 'theme.active'");
        }

        self::assertCount(1, $this->spawned);
    }

    public function testChecksAtMostOncePerInterval(): void
    {
        $detector = $this->detector(CompileTimeState::hashOf(['acme-comments'], null));
        $this->request($detector);

        $this->connection->executeStatement("UPDATE quire.extension SET enabled = true WHERE id = 'acme-seo'");
        $this->now += 4;
        $this->request($detector);
        self::assertSame([], $this->spawned, 'Not checked again within the interval.');

        ++$this->now;
        $this->request($detector);
        self::assertCount(1, $this->spawned);
    }

    public function testABuildMadeWithoutTheDatabaseIsRefreshedOnceItIsReachable(): void
    {
        $this->request($this->detector('unavailable'));

        self::assertCount(1, $this->spawned);
    }

    public function testPinnedBuildsAndSubRequestsAreNotChecked(): void
    {
        $this->request($this->detector(null));
        $this->request($this->detector('stale'), HttpKernelInterface::SUB_REQUEST);
        $this->request($this->detector('stale', interval: 0));

        self::assertSame([], $this->spawned);
    }

    public function testTheTestEnvironmentIsPinnedSoTheCheckIsOff(): void
    {
        self::assertNull(self::getContainer()->getParameterBag()->all()['quire.extensions.state_hash']);
    }

    private function detector(?string $builtForHash, int $interval = 5): StaleBuildDetector
    {
        $clock = fn (): int => $this->now;
        $refresher = new BackgroundRefresher('/app', 'prod', spawn: function (string $command): void {
            $this->spawned[] = $command;
        }, clock: $clock);

        return new StaleBuildDetector($this->connection, $refresher, $builtForHash, $interval, $clock);
    }

    private function request(StaleBuildDetector $detector, int $type = HttpKernelInterface::MAIN_REQUEST): void
    {
        $detector(new RequestEvent(self::$kernel ?? self::bootKernel(), Request::create('/'), $type));
    }
}
