<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Quire\Settings\Settings;
use Quire\User\AccountSettings;
use Quire\User\CreateUser;
use Quire\User\Event\UserRegistered;
use Quire\User\Security\FormThrottle;
use Quire\User\UpdateUser;
use Quire\User\User;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mime\Email;

/**
 * Registering, confirming an email and resetting a password.
 */
final class AccountsTest extends WebTestCase
{
    private KernelBrowser $client;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        self::getContainer()->get(FormThrottle::CACHE_POOL)->clear();
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testEverythingIsOffByDefault(): void
    {
        foreach (['/register', '/forgot-password', '/verify-email', '/reset-password'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }

        $this->client->request('GET', '/login');
        self::assertSelectorNotExists('a[href="/register"]');
        self::assertSelectorNotExists('a[href="/forgot-password"]');
    }

    public function testVisitorsRegisterAndAreSignedIn(): void
    {
        $this->settings([AccountSettings::REGISTRATION => true]);
        $registered = [];
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(UserRegistered::class, static function (UserRegistered $event) use (&$registered): void {
            $registered[] = $event->user->email;
        });

        $this->client->request('GET', '/login');
        $this->client->clickLink('Create an account');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $this->client->submitForm('Create account', ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'password' => 'correct horse']);

        self::assertResponseRedirects('/');
        self::assertSame('ada', $this->whoami(), 'Signed in.');
        self::assertSame(['member'], $this->user('ada@example.com')->roles);
        self::assertSame(['ada@example.com'], $registered);
        self::assertEmailCount(0);
    }

    public function testRegistrationShowsWhatToFix(): void
    {
        $this->settings([AccountSettings::REGISTRATION => true]);
        $this->existing('grace@example.com');

        $this->register(['email' => 'not an email', 'password' => 'short']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('#email-error', 'Enter a valid email address.');
        self::assertSelectorTextSame('#password-error', 'Passwords must be at least 8 characters long.');
        self::assertInputValueSame('email', 'not an email');

        $this->register(['email' => 'Grace@example.com', 'password' => 'correct horse']);
        self::assertSelectorTextSame('#email-error', 'Another user has this email.', 'Without confirmation, the page can only say so.');

        $this->client->request('GET', '/register');
        $this->client->submitForm('Create account', ['email' => 'ada@example.com', 'password' => 'correct horse', '_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-error', 'The form expired.');
        self::assertNull(self::getContainer()->get(UserRepository::class)->findByEmail('ada@example.com'));
    }

    public function testRegisteredUsersConfirmTheirEmailBeforeSigningIn(): void
    {
        $this->settings([AccountSettings::REGISTRATION => true, AccountSettings::EMAIL_VERIFICATION => true]);

        $this->register(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct horse']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Check your inbox');
        self::assertEmailCount(1);
        $email = $this->email();
        self::assertSame('Confirm your email address', $email->getSubject());
        self::assertSame('ada@example.com', $email->getTo()[0]->getAddress());
        self::assertFalse($this->user('ada@example.com')->hasVerifiedEmail());

        $this->signIn('ada@example.com', 'correct horse');
        self::assertSelectorTextContains('#sign-in-error', 'Confirm your email address first');
        self::assertSelectorExists('#sign-in-error a[href="/verify-email"]');

        $link = $this->link($email);
        self::assertStringStartsWith($this->siteUri().'/verify-email/', $link);
        $this->client->request('GET', $link);
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.notice', 'Your email address is confirmed.');
        self::assertTrue($this->user('ada@example.com')->hasVerifiedEmail());

        $this->signIn('ada@example.com', 'correct horse');
        self::assertSame('ada', $this->whoami());

        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(410, 'Links work once.');
    }

    public function testRegisteringWithATakenEmailTellsItsOwnerInstead(): void
    {
        $this->settings([AccountSettings::REGISTRATION => true, AccountSettings::EMAIL_VERIFICATION => true]);
        $this->existing('grace@example.com');

        $this->register(['email' => 'grace@example.com', 'password' => 'correct horse']);

        self::assertSelectorTextContains('h1', 'Check your inbox', 'The same answer as for a new email.');
        self::assertSame('You already have an account', $this->email()->getSubject());
        self::assertSame(1, $this->userCount());
    }

    public function testBotsAndFloodsAreTurnedAway(): void
    {
        $this->settings([AccountSettings::REGISTRATION => true]);

        $this->register(['email' => 'bot@example.com', 'password' => 'correct horse', 'website' => 'https://spam.example']);
        self::assertSelectorTextContains('h1', 'Check your inbox');
        self::assertSame(0, $this->userCount(), 'The trap field was filled.');

        for ($i = 0; $i < 4; ++$i) {
            $this->register(['email' => 'x', 'password' => 'correct horse']);
        }

        $this->register(['email' => 'ada@example.com', 'password' => 'correct horse']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-error', 'Too many attempts.');
        self::assertSame(0, $this->userCount());
    }

    public function testUsersResetTheirPassword(): void
    {
        $this->settings([AccountSettings::PASSWORD_RESET => true]);
        $grace = $this->existing('grace@example.com');

        $this->client->request('GET', '/login');
        $this->client->clickLink('Forgot your password?');
        $this->client->submitForm('Send the link', ['email' => 'nobody@example.com']);
        self::assertSelectorTextContains('h1', 'Check your inbox');
        self::assertEmailCount(0, message: 'No account, no email; the page reads the same.');

        $this->forgot('GRACE@example.com');
        self::assertSame('Reset your password', $this->email()->getSubject());
        $link = $this->link($this->email());
        $this->forgot('grace@example.com');
        self::assertEmailCount(0, message: 'At most one email every 2 minutes.');

        $this->client->request('GET', $link);
        self::assertResponseRedirects('/reset-password', message: 'The token leaves the address.');
        $this->client->followRedirect();
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');

        $this->client->submitForm('Change password', ['password' => 'short']);
        self::assertSelectorTextSame('#password-error', 'Passwords must be at least 8 characters long.');

        $this->client->submitForm('Change password', ['password' => 'a new horse']);
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.notice', 'Your password is changed.');

        $this->signIn('grace@example.com', 'correct horse');
        self::assertSelectorExists('#sign-in-error');
        $this->signIn('grace@example.com', 'a new horse');
        self::assertSame('grace', $this->whoami());

        $this->client->request('GET', $link);
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(410, 'Used up.');
        self::assertNotSame($grace->passwordHash, $this->user('grace@example.com')->passwordHash);
    }

    public function testLinksSentToAnEarlierEmailStopWorking(): void
    {
        $this->settings([AccountSettings::PASSWORD_RESET => true]);
        $grace = $this->existing('grace@example.com');
        $this->forgot('grace@example.com');
        $link = $this->link($this->email());

        self::getContainer()->get(UserWriter::class)->update(new UpdateUser($grace->id)->email('grace@example.org'));
        self::assertTrue($grace->hasVerifiedEmail(), 'Created by an admin.');
        self::assertFalse($this->user('grace@example.org')->hasVerifiedEmail(), 'A new email isn\'t confirmed yet.');

        $this->client->request('GET', $link);
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(410);
    }

    public function testLinksPointAtTheSitesAddressWhateverHostTheRequestNamed(): void
    {
        $this->settings([AccountSettings::PASSWORD_RESET => true]);
        $this->existing('grace@example.com');

        $this->client->request('GET', 'http://evil.example/forgot-password');
        $this->client->submitForm('Send the link', ['email' => 'grace@example.com']);

        self::assertStringStartsWith($this->siteUri().'/reset-password/', $this->link($this->email()), 'The configured address (DEFAULT_URI), not evil.example.');
    }

    public function testEmailsComeFromTheSiteUnlessASenderIsSet(): void
    {
        $this->settings([AccountSettings::PASSWORD_RESET => true]);
        $this->existing('grace@example.com');
        self::getContainer()->get(Settings::class)->set('site.title', 'Tournament news');

        $this->forgot('grace@example.com');
        $from = $this->email()->getFrom()[0];
        self::assertSame('Tournament news', $from->getName());
        self::assertSame('noreply@'.preg_replace('/^www\\./', '', (string) parse_url($this->siteUri(), \PHP_URL_HOST)), $from->getAddress());

        self::getContainer()->get(Settings::class)->set(AccountSettings::MAIL_FROM, 'News <news@example.com>');
        $this->expectExceptionMessageIsOrContains('Use an email address');
        self::getContainer()->get(Settings::class)->set(AccountSettings::MAIL_FROM, 'not an address');
    }

    /**
     * @param array<string, bool> $values
     */
    private function settings(array $values): void
    {
        foreach ($values as $key => $value) {
            self::getContainer()->get(Settings::class)->set($key, $value);
        }
    }

    private function existing(string $email): User
    {
        return self::getContainer()->get(UserWriter::class)->create(new CreateUser(ucfirst(explode('@', $email)[0]), $email, password: 'correct horse'));
    }

    /**
     * @param array<string, string> $fields
     */
    private function register(array $fields): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Create account', $fields);
    }

    private function forgot(string $email): void
    {
        $this->client->request('GET', '/forgot-password');
        $this->client->submitForm('Send the link', ['email' => $email]);
    }

    private function signIn(string $login, string $password): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => $login, '_password' => $password]);
        $this->client->followRedirect();
    }

    private function whoami(): string
    {
        $this->client->request('GET', '/_fixture/whoami');

        return (string) $this->client->getResponse()->getContent();
    }

    private function email(): Email
    {
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);

        return $message;
    }

    private function link(Email $email): string
    {
        self::assertSame(1, preg_match('#https?://\S+/(?:verify-email|reset-password)/\S+#', (string) $email->getTextBody(), $match), (string) $email->getTextBody());

        return $match[0];
    }

    private function user(string $email): User
    {
        $user = self::getContainer()->get(UserRepository::class)->findByEmail($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * The configured address as the router writes it, without a default port (CI's DEFAULT_URI has ":443").
     */
    private function siteUri(): string
    {
        $uri = rtrim(self::getContainer()->getParameter('router.request_context.base_url'), '/');

        return preg_replace('~^(https://[^/:]+):443(?=/|$)|^(http://[^/:]+):80(?=/|$)~', '$1$2', $uri) ?? $uri;
    }

    private function userCount(): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM quire.app_user');

        return \is_int($count) ? $count : -1;
    }
}
