<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Quire\Settings\Settings;
use Quire\User\CreateUser;
use Quire\User\Event\UserLoggedIn;
use Quire\User\Event\UserLoggedOut;
use Quire\User\Event\UserLoggingIn;
use Quire\User\User;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Signing in and out on Quire's sign-in page, with the firewall of config/packages/security.yaml.
 */
final class LoginTest extends WebTestCase
{
    private const string PASSWORD = 'correct horse';

    private KernelBrowser $client;

    private Connection $connection;

    private UserWriter $writer;

    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->writer = self::getContainer()->get(UserWriter::class);
        // Failed attempts of earlier tests would add up to a lock-out.
        self::getContainer()->get('cache.rate_limiter')->clear();

        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        foreach ([UserLoggingIn::class, UserLoggedIn::class, UserLoggedOut::class] as $event) {
            $dispatcher->addListener($event, function (object $event): void {
                $this->events[] = $event;
            });
        }
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testTheSignInPageIsAccessibleAndNeitherCachedNorFramed(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('main h1', 'Sign in');
        self::assertSame('username', $crawler->filter('label[for=username] + input#username')->attr('autocomplete'));
        self::assertSame('current-password', $crawler->filter('label[for=password] + input#password')->attr('autocomplete'));
        self::assertNotEmpty($crawler->filter('input[name=_csrf_token]')->attr('value'));
        self::assertSelectorTextSame('.sign-in-options a.fixture-login', 'Continue with Fixture', 'Extensions add ways to sign in.');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertResponseHeaderSame('Content-Security-Policy', "frame-ancestors 'none'");
    }

    public function testSigningInAndOut(): void
    {
        $ada = $this->user('ada');

        $this->signIn('ada@example.com', self::PASSWORD);
        self::assertResponseRedirects('/');
        self::assertSame('ada', $this->whoAmI());
        self::assertNotNull(self::getContainer()->get(UserRepository::class)->find($ada->id)?->lastLoginAt);
        self::assertSame([UserLoggingIn::class, UserLoggedIn::class], array_map(static fn (object $event): string => $event::class, $this->events));

        $crawler = $this->client->request('GET', '/login');
        self::assertSelectorTextSame('main p', 'You\'re signed in as Ada.');
        $this->client->click($crawler->selectLink('Sign out')->link());
        self::assertResponseRedirects('/');
        self::assertSame('anonymous', $this->whoAmI());
        self::assertInstanceOf(UserLoggedOut::class, end($this->events));
    }

    public function testFailuresDontRevealWhetherAnAccountExists(): void
    {
        $this->user('ada');

        foreach (['ada@example.com', 'nobody@example.com'] as $username) {
            $this->signIn($username, 'wrong password');
            $crawler = $this->client->followRedirect();

            self::assertSelectorTextSame('.form-error', 'Invalid credentials.', $username);
            self::assertSame($username, $crawler->filter('input#username')->attr('value'), 'What was typed is kept.');
        }
    }

    public function testOnlySomeoneWithThePasswordLearnsThatAnAccountIsSuspended(): void
    {
        $this->writer->suspend($this->user('grace')->id);

        $this->signIn('grace', 'wrong password');
        $this->client->followRedirect();
        self::assertSelectorTextSame('.form-error', 'Invalid credentials.');

        $this->signIn('grace', self::PASSWORD);
        $this->client->followRedirect();
        self::assertSelectorTextSame('.form-error', 'This account is suspended.');
        self::assertSame('anonymous', $this->whoAmI());
    }

    public function testTheFormNeedsItsCsrfToken(): void
    {
        $this->user('ada');

        $this->client->request('POST', '/login', ['_username' => 'ada', '_password' => self::PASSWORD]);
        $this->client->followRedirect();

        self::assertSelectorTextSame('.form-error', 'Invalid CSRF token.');
        self::assertSame('anonymous', $this->whoAmI());
    }

    public function testRepeatedFailuresAreThrottled(): void
    {
        $this->user('edsger');

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->signIn('edsger', 'wrong password '.$attempt);
        }

        $this->signIn('edsger', self::PASSWORD);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.form-error', 'Too many failed login attempts');
        self::assertSame('anonymous', $this->whoAmI());
    }

    public function testRememberMeKeepsTheUserSignedInUntilThePasswordChanges(): void
    {
        $ada = $this->user('ada');
        $this->signIn('ada', self::PASSWORD, ['_remember_me' => 'on']);
        $remember = $this->client->getCookieJar()->get('REMEMBERME');
        self::assertInstanceOf(Cookie::class, $remember);

        // A new visit: the session is gone, the cookie is still there.
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($remember);
        self::assertSame('ada', $this->whoAmI());

        $this->writer->changePassword($ada->id, 'another password');
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($remember);
        self::assertSame('anonymous', $this->whoAmI());
    }

    public function testReturnsToTheRequestedPageButNeverToAnotherSite(): void
    {
        $this->user('ada');

        $crawler = $this->client->request('GET', '/login?next=/about');
        self::assertSame('/about', $crawler->filter('input[name=_target_path]')->attr('value'));
        $this->client->submit($crawler->selectButton('Sign in')->form(['_username' => 'ada', '_password' => self::PASSWORD]));
        self::assertResponseRedirects('/about');

        foreach (['https://evil.example/', '//evil.example/'] as $target) {
            $this->client->request('GET', '/login?next='.urlencode($target));
            self::assertSelectorNotExists('input[name=_target_path]', $target);

            $this->client->restart();
            $this->signIn('ada', self::PASSWORD, ['_target_path' => $target]);
            self::assertResponseRedirects();
            self::assertSame('localhost', parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_HOST), 'Even when posted directly: '.$target);
        }
    }

    public function testExtensionsCanRefuseASignIn(): void
    {
        $this->user('ada');
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(UserLoggingIn::class, static function (UserLoggingIn $event): void {
            $event->cancel('Only admins can sign in during maintenance.');
        });

        $this->signIn('ada', self::PASSWORD);
        $this->client->followRedirect();

        self::assertSelectorTextSame('.form-error', 'Only admins can sign in during maintenance.');
        self::assertSame('anonymous', $this->whoAmI());
    }

    public function testPasswordSignInCanBeTurnedOff(): void
    {
        $this->user('ada');
        self::getContainer()->get(Settings::class)->set('quire.auth.password_login', false);

        $this->client->request('GET', '/login');
        self::assertSelectorNotExists('input[name=_password]');
        self::assertSelectorExists('.sign-in-options a.fixture-login', 'Other ways to sign in stay.');

        $this->client->request('POST', '/login', ['_username' => 'ada', '_password' => self::PASSWORD, '_csrf_token' => 'irrelevant']);
        $this->client->followRedirect();
        self::assertSelectorTextSame('.form-error', 'Signing in with a password is turned off.');
        self::assertSame('anonymous', $this->whoAmI());
    }

    public function testTheSignInPageSpeaksTheSitesLanguage(): void
    {
        self::getContainer()->get(Settings::class)->set('site.locale', 'de');

        $this->client->request('GET', '/login');

        self::assertSelectorTextSame('main h1', 'Anmelden');
        self::assertSelectorTextSame('label[for=username]', 'E-Mail oder Benutzername');
    }

    private function user(string $name): User
    {
        return $this->writer->create(new CreateUser(ucfirst($name), $name.'@example.com', password: self::PASSWORD));
    }

    /**
     * @param array<string, string> $extra
     */
    private function signIn(string $username, string $password, array $extra = []): void
    {
        $crawler = $this->client->request('GET', '/login');
        $token = (string) $crawler->filter('input[name=_csrf_token]')->attr('value');
        $this->client->request('POST', '/login', ['_username' => $username, '_password' => $password, '_csrf_token' => $token, ...$extra]);
    }

    private function whoAmI(): string
    {
        $this->client->request('GET', '/_fixture/whoami');

        return (string) $this->client->getResponse()->getContent();
    }
}
