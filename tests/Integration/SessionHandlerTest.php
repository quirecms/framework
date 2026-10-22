<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Http\Session\SessionHandlerFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Sessions in PostgreSQL, through a connection of their own. (Tests themselves use Symfony's mock
 * session storage.).
 */
final class SessionHandlerTest extends KernelTestCase
{
    public function testStoresSessionsInQuiresSchema(): void
    {
        $handler = self::getContainer()->get(SessionHandlerFactory::SERVICE);
        $id = 'quire-test-'.bin2hex(random_bytes(8));

        try {
            $handler->open('', 'sid');
            $handler->read($id);
            $handler->write($id, 'signed-in-as|ada');
            $handler->close();

            self::assertSame(1, $this->rows($id), 'Written, and committed by its own connection.');

            $handler->open('', 'sid');
            self::assertSame('signed-in-as|ada', $handler->read($id));
            $handler->destroy($id);
            $handler->close();

            self::assertSame(0, $this->rows($id));
        } finally {
            self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM quire.session WHERE sess_id = ?', [$id]);
        }
    }

    private function rows(string $id): int
    {
        $count = self::getContainer()->get(Connection::class)->fetchOne('SELECT count(*) FROM quire.session WHERE sess_id = ?', [$id]);
        \assert(is_numeric($count));

        return (int) $count;
    }
}
