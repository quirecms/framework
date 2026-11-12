<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Quire\Csp\Csp;
use Quire\Csp\PolicyKind;
use Quire\Testing\TestInstallation;
use Quire\User\CreateUser;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * One Content-Security-Policy per response, from Quire (doc 20, D-49 and condition C-A): browsers apply
 * every policy a response has, so a second one would quietly block what the first allows.
 */
final class CspTest extends WebTestCase
{
    private KernelBrowser $client;

    private ?Connection $connection = null;

    protected function tearDown(): void
    {
        $this->connection?->rollBack();
        parent::tearDown();
    }

    public function testATemplatePageHasExactlyOnePolicy(): void
    {
        $this->start();
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame([$this->policy(PolicyKind::Page)], $this->sent());
        self::assertFalse($this->client->getResponse()->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function testTheSitesOwnPagesRunNothingInlineButWhatTheirPolicyAllows(): void
    {
        $this->start();
        $crawler = $this->client->request('GET', '/');
        $header = (string) $this->client->getResponse()->headers->get('Content-Security-Policy');

        self::assertCount(0, $crawler->filter('[style]'), 'No style attributes: the policy allows none.');
        self::assertCount(0, $crawler->filter('style:not([nonce])'), 'No style elements without the response\'s nonce.');
        $scripts = $crawler->filter('script:not([src]):not([type="application/ld+json"])');
        self::assertGreaterThan(0, $scripts->count(), 'The importmap, which the fixture extension\'s script needs.');

        foreach ($scripts as $script) {
            // As browsers read it: the element's text, exactly.
            self::assertStringContainsString(\sprintf("'sha256-%s'", base64_encode(hash('sha256', $script->textContent, true))), $header, 'Each inline script is allowed by its hash, the same for every visitor.');
        }

        self::assertFalse($this->client->getResponse()->headers->hasCacheControlDirective('no-store'), 'Hashes, unlike nonces, keep the page cacheable.');
    }

    public function testStylesheetsThatScriptsImportLoadWithoutDataScripts(): void
    {
        $this->start();
        $crawler = $this->client->request('GET', '/');
        $map = json_decode($crawler->filter('script[type=importmap]')->text(), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($map) && \is_array($map['imports']));

        self::assertSame('/_quire/empty.js', $map['imports']['/assets/styles/app.css'] ?? null, 'The site\'s app.js imports its stylesheet, which is linked already: an empty module of the site\'s, not a data: script the policy refuses.');
        self::assertSame([], array_filter($map['imports'], static fn (mixed $url): bool => \is_string($url) && str_starts_with($url, 'data:')));

        $this->client->request('GET', '/_quire/empty.js');
        self::assertResponseIsSuccessful();
        self::assertSame('', $this->client->getInternalResponse()->getContent());
        self::assertResponseHeaderSame('Content-Type', 'text/javascript; charset=utf-8');
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('immutable'));
    }

    public function testErrorPagesHaveThePagesPolicyToo(): void
    {
        // Not in debug mode, whose exception page Symfony serves without a policy.
        $this->start(debug: false);
        $this->client->request('GET', '/no-such-page');

        self::assertResponseStatusCodeSame(404);
        self::assertSame([$this->policy(PolicyKind::Page)], $this->sent());

        $this->client->request('GET', '/forgot-password');
        self::assertResponseStatusCodeSame(404, 'Resetting passwords is off.');
        self::assertSame([$this->policy(PolicyKind::Private)], $this->sent());
    }

    public function testPagesWithPasswordFormsAreNeverFramed(): void
    {
        $this->start();
        $this->client->request('GET', '/login');

        self::assertSame([$this->policy(PolicyKind::Private)], $this->sent());
    }

    public function testTheAdminHasItsOwnPolicy(): void
    {
        $this->start();
        self::getContainer()->get(UserWriter::class)->create(new CreateUser('Ada', 'ada@example.com', password: 'correct-horse-42', roles: ['admin']));
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => 'ada', '_password' => 'correct-horse-42']);
        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSame([$this->policy(PolicyKind::Admin)], $this->sent());
    }

    public function testDataHasNoPolicy(): void
    {
        $this->start();
        $this->client->request('GET', '/_quire/health');

        self::assertFalse($this->client->getResponse()->headers->has('Content-Security-Policy'));
    }

    public function testCaddySandboxesTheFilesItServesAsQuireDoes(): void
    {
        $caddyfile = (string) file_get_contents(TestInstallation::projectDir().'/frankenphp/Caddyfile');

        self::assertStringContainsString(\sprintf('header Content-Security-Policy "%s"', Csp::STORED_FILE), $caddyfile);
        self::assertSame(1, substr_count($caddyfile, 'Content-Security-Policy'), 'The Caddyfile sends no policy for pages: Quire does (condition C-A).');
    }

    private function start(bool $debug = true): void
    {
        $this->client = self::createClient(['debug' => $debug]);
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    /**
     * The response's policies, without the hashes of its inline scripts (see the test above).
     *
     * @return list<string>
     */
    private function sent(): array
    {
        return array_map(static fn (?string $header): string => (string) preg_replace("/ 'sha256-[A-Za-z0-9+\\/]+={0,2}'/", '', (string) $header), $this->client->getResponse()->headers->all('content-security-policy'));
    }

    private function policy(PolicyKind $kind): string
    {
        return self::getContainer()->get(Csp::class)->policy($kind)->header();
    }
}
