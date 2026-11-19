<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Quire\Migrations\Version20261118190000;
use Quire\Settings\Settings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * New sites start with an edition as their homepage; sites installed before keep the list they showed.
 */
final class HomepageDefaultMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->connection->executeStatement("DELETE FROM quire.setting WHERE name = 'site.homepage'");
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();

        parent::tearDown();
    }

    public function testASiteWithUsersKeepsTheList(): void
    {
        $this->connection->executeStatement("INSERT INTO quire.app_user (username, display_name) VALUES ('old', 'Old')");

        $this->migrate();

        self::assertSame('"listing"', $this->stored());
    }

    public function testANewSiteGetsTheNewDefault(): void
    {
        $this->connection->executeStatement('DELETE FROM quire.app_user');

        $this->migrate();

        self::assertFalse($this->stored());
        self::assertSame('edition', self::getContainer()->get(Settings::class)->get('site.homepage'));
    }

    public function testAChoiceAlreadyMadeStays(): void
    {
        $this->connection->executeStatement("INSERT INTO quire.app_user (username, display_name) VALUES ('old', 'Old')");
        $this->connection->executeStatement("INSERT INTO quire.setting (name, value, updated_at) VALUES ('site.homepage', '\"page\"', now())");

        $this->migrate();

        self::assertSame('"page"', $this->stored());
    }

    private function migrate(): void
    {
        // Migrations aren't autoloaded: Doctrine finds them in their directory
        require_once \dirname(__DIR__, 2).'/packages/core/migrations/Version20261118190000.php';
        $migration = new Version20261118190000($this->connection, new NullLogger());
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }

    private function stored(): string|false
    {
        $value = $this->connection->fetchOne("SELECT value::text FROM quire.setting WHERE name = 'site.homepage'");

        return \is_string($value) ? str_replace(' ', '', $value) : false;
    }
}
