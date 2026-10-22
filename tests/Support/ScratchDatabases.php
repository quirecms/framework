<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\After;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Throwaway databases, for tests whose data must be committed: pg_dump and pg_restore work over
 * connections of their own, so they don't see a test's transaction (and a restore would wait for it).
 *
 * For use in a {@see \Symfony\Bundle\FrameworkBundle\Test\WebTestCase}.
 */
trait ScratchDatabases
{
    /** @var list<string> Created by the test, dropped after it */
    private array $scratchDatabases = [];

    private ?string $defaultDatabaseUrl = null;

    /**
     * Creates an empty database and returns a client whose kernel is connected to it.
     */
    private function clientForNewDatabase(string $name, bool $migrate = true): KernelBrowser
    {
        self::ensureKernelShutdown();
        $this->defaultDatabaseUrl ??= $this->databaseUrl();
        $url = (string) preg_replace('#^([^?]*/)([^/?]+)#', '${1}${2}_'.$name, $this->defaultDatabaseUrl);
        $this->setDatabaseUrl($url);

        $client = self::createClient();
        $client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        $database = $connection->getParams()['dbname'] ?? null;
        \assert(\is_string($database));

        $this->admin(static function (Connection $admin) use ($database): void {
            $admin->executeStatement(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', $admin->quoteSingleIdentifier($database)));
            $admin->executeStatement(\sprintf('CREATE DATABASE %s', $admin->quoteSingleIdentifier($database)));
        });
        $this->scratchDatabases[] = $database;

        if ($migrate) {
            $this->console('quire:migrations:migrate', ['--allow-no-migration' => true]);
        }

        return $client;
    }

    /**
     * Runs a console command in the current kernel and returns its output, with line breaks and padding
     * collapsed to single spaces.
     *
     * @param array<string, mixed> $input
     */
    private function console(string $command, array $input = [], int $expectedStatus = 0): string
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('Boot a kernel first.'));
        $tester = new CommandTester($application->find($command));
        $status = $tester->execute($input, ['interactive' => false]);
        self::assertSame($expectedStatus, $status, \sprintf("\"%s\" exited with %d:\n%s", $command, $status, $tester->getDisplay()));

        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    #[After]
    protected function dropScratchDatabases(): void
    {
        self::ensureKernelShutdown();

        if (null === $this->defaultDatabaseUrl) {
            return;
        }

        $defaultUrl = $this->defaultDatabaseUrl;
        $databases = $this->scratchDatabases;
        $this->admin(static function (Connection $admin) use ($databases): void {
            foreach ($databases as $database) {
                $admin->executeStatement(\sprintf('DROP DATABASE IF EXISTS %s WITH (FORCE)', $admin->quoteSingleIdentifier($database)));
            }
        });
        $this->setDatabaseUrl($defaultUrl);
        $this->scratchDatabases = [];
        $this->defaultDatabaseUrl = null;
    }

    /**
     * @param \Closure(Connection): void $run Runs with a connection to the maintenance database
     */
    private function admin(\Closure $run): void
    {
        $url = $this->defaultDatabaseUrl ?? throw new \LogicException('No default database.');
        $connection = DriverManager::getConnection(['driver' => 'pdo_pgsql', ...$this->connectionParams($url)]);

        try {
            $run($connection);
        } finally {
            $connection->close();
        }
    }

    /**
     * @return array{host: string, port: int, user: string, password: string, dbname: string}
     */
    private function connectionParams(string $url): array
    {
        $parts = parse_url($url);
        \assert(\is_array($parts));

        // PostgreSQL's maintenance database, which always exists, to create and drop the others from.
        return [
            'host' => $parts['host'] ?? 'localhost',
            'port' => $parts['port'] ?? 5432,
            'user' => urldecode($parts['user'] ?? ''),
            'password' => urldecode($parts['pass'] ?? ''),
            'dbname' => 'postgres',
        ];
    }

    private function databaseUrl(): string
    {
        $url = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL');

        return \is_string($url) ? $url : throw new \LogicException('DATABASE_URL isn\'t set.');
    }

    private function setDatabaseUrl(string $url): void
    {
        $_ENV['DATABASE_URL'] = $url;
        $_SERVER['DATABASE_URL'] = $url;
    }
}
