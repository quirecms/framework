<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
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
}
