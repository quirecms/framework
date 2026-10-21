<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Extension\State\DatabaseExtensionStateStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DatabaseExtensionStateStoreTest extends KernelTestCase
{
    private Connection $connection;

    private DatabaseExtensionStateStore $store;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement('DELETE FROM extension');
        $this->store = new DatabaseExtensionStateStore($this->connection);
    }

    public function testEnableAndDisable(): void
    {
        self::assertSame([], $this->store->enabledIds());
        self::assertNull($this->store->readOnlyReason());

        $this->store->enable(['quire-seo', 'quire-comments']);
        self::assertSame(['quire-comments', 'quire-seo'], $this->store->enabledIds());

        $this->store->disable(['quire-seo']);
        self::assertSame(['quire-comments'], $this->store->enabledIds());

        // Disabled extensions keep their row, so their history isn't lost.
        self::assertEquals(2, $this->connection->fetchOne('SELECT COUNT(*) FROM extension'));
    }

    public function testEnablingTwiceIsHarmless(): void
    {
        $this->store->enable(['quire-seo']);
        $this->store->enable(['quire-seo']);

        self::assertSame(['quire-seo'], $this->store->enabledIds());
    }
}
