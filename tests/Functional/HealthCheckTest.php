<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthCheckTest extends WebTestCase
{
    public function testReportsHealthyWhenDatabaseAndCacheAreReachable(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_quire/health');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(
            ['status' => 'ok', 'checks' => ['cache' => ['status' => 'ok'], 'database' => ['status' => 'ok'], 'extensions' => ['status' => 'ok']]],
            json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function testResponseIsNeverCached(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_quire/health');

        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
    }
}
