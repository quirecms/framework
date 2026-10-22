<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Quire\Content\Command\CreateContent;
use Quire\Content\ContentWriter;
use Quire\Content\Status;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FrankenPHP worker mode serves many requests from one booted kernel.
 *
 * These tests do the same: they disable the kernel reboot between requests, so any
 * state leaking from one request into the next shows up as a failure (ARC-023).
 */
final class WorkerModeTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRoutes(): iterable
    {
        yield 'homepage' => ['/'];
        yield 'health' => ['/_quire/health'];
    }

    #[DataProvider('provideRoutes')]
    public function testRepeatedRequestsInOneKernelReturnIdenticalResponses(string $path): void
    {
        $client = self::createClient();
        $client->disableReboot();

        $client->request('GET', $path);
        $first = $client->getResponse();

        $client->request('GET', $path);
        $second = $client->getResponse();

        self::assertSame($first->getStatusCode(), $second->getStatusCode());
        self::assertSame($first->getContent(), $second->getContent());
    }

    public function testSettingsChangedElsewhereApplyToTheNextRequest(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        $connection->beginTransaction();

        try {
            $writer = self::getContainer()->get(ContentWriter::class);
            $post = $writer->create(new CreateContent('post', 'Hello'));
            $writer->transition($post->id, $post->version, Status::PUBLISH, new \DateTimeImmutable('-1 hour'));

            $client->request('GET', '/blog/hello');
            self::assertResponseIsSuccessful();

            // Another server changes the pattern: this worker's settings and patterns must not be stale.
            $connection->executeStatement("INSERT INTO quire.setting (name, value, updated_at) VALUES ('quire.permalinks.post', '\"/news/{slug}\"', now())");

            $client->request('GET', '/news/hello');
            self::assertResponseIsSuccessful();
        } finally {
            $connection->rollBack();
        }
    }
}
