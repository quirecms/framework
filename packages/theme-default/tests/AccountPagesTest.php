<?php

declare(strict_types=1);

namespace Quire\ThemeDefault\Tests;

use Psr\Cache\CacheItemPoolInterface;
use Quire\Testing\ExtensionTestCase;
use Quire\User\CreateUser;
use Quire\User\Security\FormThrottle;
use Quire\User\UserWriter;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Core's account pages in the default theme's card: the theme frames core's "account" block and nothing
 * else, so the forms keep core's fields, their autocomplete, the CSRF tokens and the honeypot, and the
 * pages that must not tell whether an address has an account (D-44) stay identical either way.
 */
final class AccountPagesTest extends ExtensionTestCase
{
    #[\Override]
    protected static ?string $theme = 'quire-theme-default';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        // The forms' throttles count per client: start from none, whatever ran before
        foreach ([FormThrottle::CACHE_POOL, 'cache.rate_limiter'] as $pool) {
            /** @var CacheItemPoolInterface $cache */
            $cache = self::getContainer()->get($pool);
            $cache->clear();
        }
        $this->setSetting('quire.auth.registration', true);
        $this->setSetting('quire.auth.email_verification', true);
        $this->setSetting('quire.auth.password_reset', true);
    }

    public function testTheSignInFormIsCoresInTheThemesCard(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->filter('.qd-auth--card .qd-auth__form form.form');

        self::assertCount(1, $form, 'Core\'s form, in the card.');
        self::assertSame('username', $form->filter('input[name=_username]')->attr('autocomplete'));
        self::assertSame('current-password', $form->filter('input[name=_password]')->attr('autocomplete'));
        self::assertSame('password', $form->filter('.form-password .password-toggle')->attr('aria-controls'), 'The field and its "Show".');
        self::assertNotSame('', (string) $form->filter('input[type=hidden][name=_csrf_token]')->attr('value'));
        self::assertCount(1, $form->filter('input[name=_remember_me]'));
        self::assertSame(['Forgot your password?', 'Create an account'], $crawler->filter('.qd-auth__form .account-links a')->each(static fn (Crawler $a): string => $a->text()));
        self::assertCount(1, $crawler->filter('.qd-auth__form script[src*="show-password"]'), 'Core\'s script comes with its block.');
        self::assertSame('Sign in to Quire', $crawler->filter('.qd-auth__form h1')->text());
    }

    public function testTheRegistrationFormKeepsItsHoneypotAndChecks(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('.qd-auth__form form.form');

        self::assertSame(['name', 'email', 'new-password'], $form->filter('input[name=name], input[name=email], input[name=password]')->each(static fn (Crawler $i): string => (string) $i->attr('autocomplete')));
        self::assertSame('8', $form->filter('input[name=password]')->attr('minlength'));
        self::assertSame('-1', $form->filter('p[hidden] input[name=website]')->attr('tabindex'), 'The honeypot, hidden from people.');
        self::assertNotSame('', (string) $form->filter('input[type=hidden][name=_csrf_token]')->attr('value'));

        $crawler = $this->client->submitForm('Create account', ['name' => 'Lin', 'email' => 'not-an-email', 'password' => 'short']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Two fields need fixing: Email, Password.', $crawler->filter('.form-summary')->text());
        self::assertSame(['#email', '#password'], $crawler->filter('.form-summary a')->each(static fn (Crawler $a): string => (string) $a->attr('href')), 'Each a link to its field.');
        self::assertSame('true', $crawler->filter('input#email')->attr('aria-invalid'));
    }

    public function testWhetherAnAddressHasAnAccountDoesntShow(): void
    {
        $this->service(UserWriter::class)->create(new CreateUser('Grace', 'grace@example.com', password: 'correct horse'));

        foreach (['register' => ['/register', 'Create account', ['name' => 'G', 'password' => 'correct horse 2']], 'forgot' => ['/forgot-password', 'Send the link', []]] as $flow => [$path, $button, $fields]) {
            $pages = [];
            foreach (['grace@example.com', 'nobody@example.com'] as $email) {
                $this->client->request('GET', $path);
                $this->client->submitForm($button, ['email' => $email, ...$fields]);
                self::assertResponseIsSuccessful();
                $pages[] = str_replace($email, 'EMAIL', $this->stable((string) $this->client->getResponse()->getContent()));
            }

            self::assertSame($pages[0], $pages[1], $flow.': the same page for an address with an account and one without (D-44).');
        }
    }

    /**
     * The page without what differs on every request (tokens, nonces).
     */
    private function stable(string $html): string
    {
        return (string) preg_replace(['/nonce="[^"]*"/', '/name="_csrf_token" value="[^"]*"/', '/"csrf[^"]*":"[^"]*"/'], ['nonce=""', 'name="_csrf_token" value=""', ''], $html);
    }
}
