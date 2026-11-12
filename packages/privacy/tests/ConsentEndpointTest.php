<?php

declare(strict_types=1);

namespace Quire\Privacy\Tests;

use Psr\Cache\CacheItemPoolInterface;
use Quire\Privacy\ConsentLog;
use Quire\Privacy\Internal\Http\ConsentController;
use Quire\Privacy\Services;
use Symfony\Component\ErrorHandler\BufferingLogger;

/**
 * Where the loader logs a choice: only from the site's own pages, only answers about the services as
 * they are, small, and so many an hour per choice and per address.
 */
final class ConsentEndpointTest extends PrivacyTestCase
{
    private const string URL = '/_quire/privacy/consent';
    private const string ID = '0123456789abcdef0123456789abcdef';

    public function testTheSitesOwnPagesLogAChoice(): void
    {
        $this->post(['id' => self::ID, 's' => ['fixture-video' => $this->revision(), 'fixture-fonts' => false]]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(1, $this->connection()->fetchOne('SELECT count(*) FROM quire.quire_privacy_consent WHERE consent_id = ?', [self::ID]));
        self::assertSame([], $this->client->getCookieJar()->all(), 'No session, no cookie: the page stays cacheable.');
    }

    public function testBrowsersWithoutFetchMetadataAreCheckedByTheirOrigin(): void
    {
        $this->post(['id' => self::ID, 's' => ['fixture-video' => false]], ['HTTP_SEC_FETCH_SITE' => null, 'HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(204);

        $this->post(['id' => self::ID, 's' => ['fixture-video' => false]], ['HTTP_SEC_FETCH_SITE' => null, 'HTTP_ORIGIN' => 'https://evil.example']);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return iterable<string, array{array<string, string|null>}>
     */
    public static function notFromTheSite(): iterable
    {
        yield 'another site' => [['HTTP_SEC_FETCH_SITE' => 'cross-site']];
        yield 'a sibling subdomain' => [['HTTP_SEC_FETCH_SITE' => 'same-site']];
        yield 'typed or bookmarked' => [['HTTP_SEC_FETCH_SITE' => 'none']];
        yield 'neither header' => [['HTTP_SEC_FETCH_SITE' => null]];
        yield 'an Origin that only starts the same' => [['HTTP_SEC_FETCH_SITE' => null, 'HTTP_ORIGIN' => 'http://localhost.evil.example']];
    }

    /**
     * @param array<string, string|null> $headers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('notFromTheSite')]
    public function testOtherSitesCantLogChoices(array $headers): void
    {
        $this->post(['id' => self::ID, 's' => ['fixture-video' => false]], $headers);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->connection()->fetchOne('SELECT count(*) FROM quire.quire_privacy_consent'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAChoice(): iterable
    {
        yield 'not JSON' => ['id=1'];
        yield 'no answers' => ['{"id": "'.self::ID.'"}'];
        yield 'answers as a list' => ['{"id": "'.self::ID.'", "s": [false]}'];
        yield 'an answer that is true' => ['{"id": "'.self::ID.'", "s": {"fixture-video": true}}'];
        yield 'a stale revision' => ['{"id": "'.self::ID.'", "s": {"fixture-video": "abcdefabcdef"}}'];
        yield 'an unknown service' => ['{"id": "'.self::ID.'", "s": {"acme": false}}'];
        yield 'an ID that isn\'t one' => ['{"id": "me@example.com", "s": {"fixture-video": false}}'];
        yield 'nested too deep' => ['{"id": "'.self::ID.'", "s": {"fixture-video": [[[false]]]}}'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notAChoice')]
    public function testOnlyAChoiceIsTaken(string $body): void
    {
        $this->post($body);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->connection()->fetchOne('SELECT count(*) FROM quire.quire_privacy_consent'));
    }

    public function testALongBodyIsntRead(): void
    {
        $this->post('{"id": "'.self::ID.'", "s": {"fixture-video": false}, "x": "'.str_repeat('a', 9000).'"}');

        self::assertResponseStatusCodeSame(413);
    }

    public function testAChoiceIsLoggedSoManyTimesAnHour(): void
    {
        $address = '203.0.113.'.random_int(1, 254);
        $id = bin2hex(random_bytes(16));

        for ($i = 0; $i < ConsentController::PER_CHOICE; ++$i) {
            $this->post(['id' => $id, 's' => ['fixture-video' => false]], ['REMOTE_ADDR' => $address]);
            self::assertResponseStatusCodeSame(204);
        }

        $this->post(['id' => $id, 's' => ['fixture-video' => false]], ['REMOTE_ADDR' => '198.51.100.'.random_int(1, 254)]);
        self::assertResponseStatusCodeSame(429, 'From another address too.');

        $this->post(['id' => bin2hex(random_bytes(16)), 's' => ['fixture-video' => false]], ['REMOTE_ADDR' => $address]);
        self::assertResponseStatusCodeSame(204, 'Other choices from the same address still are.');
    }

    public function testManyVisitorsShareAnAddressUpToItsLimitAndTheOwnerHearsOfMore(): void
    {
        // One kernel, whose endpoint logs to a buffer; and no warning given already by another run.
        $this->client->disableReboot();
        $cache = $this->service(CacheItemPoolInterface::class);
        $cache->clear();
        $logger = new BufferingLogger();
        self::getContainer()->set(ConsentController::class, new ConsentController($this->service(ConsentLog::class), $cache, $logger));
        $address = '203.0.113.'.random_int(1, 254);

        for ($i = 0; $i < ConsentController::PER_ADDRESS; ++$i) {
            $this->post(['id' => bin2hex(random_bytes(16)), 's' => ['fixture-video' => false]], ['REMOTE_ADDR' => $address]);
            self::assertResponseStatusCodeSame(204);
        }

        for ($i = 0; $i < 3; ++$i) {
            $this->post(['id' => bin2hex(random_bytes(16)), 's' => ['fixture-video' => false]], ['REMOTE_ADDR' => $address]);
            self::assertResponseStatusCodeSame(429);
        }

        $this->post(['id' => bin2hex(random_bytes(16)), 's' => ['fixture-video' => false]], ['REMOTE_ADDR' => '198.51.100.'.random_int(1, 254)]);
        self::assertResponseStatusCodeSame(204, 'Other addresses still can.');

        /** @var list<array{string, string, array<string, mixed>}> $logs */
        $logs = $logger->cleanLogs();
        $warnings = array_values(array_filter($logs, static fn (array $log): bool => 'warning' === $log[0]));
        self::assertCount(1, $warnings, 'Once, for many refusals.');
        self::assertStringContainsString('weren\'t logged as proof', $warnings[0][1]);
        self::assertSame(['route' => ConsentController::ROUTE, 'per' => 'address', 'limit' => ConsentController::PER_ADDRESS], $warnings[0][2]);
        self::assertStringNotContainsString($address, json_encode($warnings, \JSON_THROW_ON_ERROR), 'Never the address.');
    }

    public function testOnlyPost(): void
    {
        $this->client->request('GET', self::URL);

        self::assertResponseStatusCodeSame(404);
    }

    public function testThePagesTellTheLoaderWhereToLog(): void
    {
        $crawler = $this->client->request('GET', '/');
        /** @var array{log: string} $config */
        $config = json_decode($crawler->filter('script#quire-consent-config')->text(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(self::URL, $config['log']);
    }

    /**
     * @param array<string, mixed>|string $body
     * @param array<string, string|null>  $headers Server variables; null leaves one out
     */
    private function post(array|string $body, array $headers = []): void
    {
        $server = array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'REMOTE_ADDR' => '192.0.2.'.random_int(1, 254),
            ...$headers,
        ], static fn (?string $value): bool => null !== $value);

        $this->client->request('POST', self::URL, server: $server, content: \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function revision(): string
    {
        return $this->service(Services::class)->get('fixture-video')?->revision() ?? self::fail('No service.');
    }
}
