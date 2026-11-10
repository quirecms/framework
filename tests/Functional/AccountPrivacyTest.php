<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Quire\Settings\Settings;
use Quire\User\AccountSettings;
use Quire\User\CreateUser;
use Quire\User\Security\FormThrottle;
use Quire\User\User;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherAwareInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * Nobody finds out who has an account from how long an answer takes (D-44): signing in, and registering,
 * do the same password work whatever the address. Counted, not timed: a hasher that counts what it does.
 */
final class AccountPrivacyTest extends WebTestCase
{
    private KernelBrowser $client;

    private Connection $connection;

    /** @var \ArrayObject<string, int> What the hasher did: hash, verify */
    private \ArrayObject $work;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->connection->beginTransaction();
        $container->get(FormThrottle::CACHE_POOL)->clear();
        $container->get('cache.rate_limiter')->clear();

        $this->work = new \ArrayObject(['hash' => 0, 'verify' => 0]);
        // The test environment's hasher (config/packages/security.yaml), counted
        $settings = ['algorithm' => 'auto', 'cost' => 4, 'time_cost' => 3, 'memory_cost' => 10];
        $container->set('security.password_hasher_factory', $this->counting(new PasswordHasherFactory([User::class => $settings, PasswordAuthenticatedUserInterface::class => $settings])));
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testSigningInChecksOnePasswordWhateverTheAccount(): void
    {
        $writer = self::getContainer()->get(UserWriter::class);
        $writer->create(new CreateUser('Grace', 'grace@example.com', password: 'correct horse'));
        // Single sign-on only: no password
        $writer->create(new CreateUser('Kim', 'kim@example.com'));
        // The dummy hash is made once, and kept: not counted
        $this->signIn('nobody@example.com', 'wrong horse');

        $answers = [];

        foreach (['grace@example.com' => 'a wrong password', 'nobody@example.com' => 'no such account', 'kim@example.com' => 'an account without a password'] as $login => $case) {
            $this->work->exchangeArray(['hash' => 0, 'verify' => 0]);
            $this->signIn($login, 'wrong horse');

            self::assertSame(['hash' => 0, 'verify' => 1], $this->work->getArrayCopy(), $case);
            $answers[$case] = $this->client->getCrawler()->filter('#sign-in-error')->text();
        }

        self::assertSame(['Invalid credentials.'], array_values(array_unique($answers)), 'The same answer for each.');
    }

    public function testRegisteringHashesThePasswordWhateverTheAddress(): void
    {
        $this->settings([AccountSettings::REGISTRATION => true, AccountSettings::EMAIL_VERIFICATION => true]);
        self::getContainer()->get(UserWriter::class)->create(new CreateUser('Grace', 'grace@example.com', password: 'correct horse'));
        $pages = [];

        foreach (['grace@example.com' => 'a taken address', 'ada@example.com' => 'a new address'] as $email => $case) {
            $this->work->exchangeArray(['hash' => 0, 'verify' => 0]);
            $this->client->request('GET', '/register');
            $this->client->submitForm('Create account', ['name' => 'Someone', 'email' => $email, 'password' => 'correct horse']);

            self::assertResponseIsSuccessful($case);
            self::assertSame(['hash' => 1, 'verify' => 0], $this->work->getArrayCopy(), $case);
            $pages[$case] = str_replace($email, '{email}', (string) $this->client->getResponse()->getContent());
        }

        self::assertSame($pages['a new address'], $pages['a taken address'], 'The same page, but for the address typed.');
    }

    public function testRegistrationIsClosedWithoutEmailConfirmation(): void
    {
        $this->settings([AccountSettings::REGISTRATION => true, AccountSettings::EMAIL_VERIFICATION => false]);

        $this->client->request('GET', '/register');
        self::assertResponseStatusCodeSame(404, 'A new address would be signed in at once, and a taken one couldn\'t be.');

        $this->client->request('GET', '/login');
        self::assertSelectorNotExists('a[href="/register"]');
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

    private function signIn(string $login, string $password): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => $login, '_password' => $password]);
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
    }

    private function counting(PasswordHasherFactoryInterface $factory): PasswordHasherFactoryInterface
    {
        return new readonly class($factory, $this->work) implements PasswordHasherFactoryInterface {
            /** @param \ArrayObject<string, int> $work */
            public function __construct(private PasswordHasherFactoryInterface $factory, private \ArrayObject $work)
            {
            }

            public function getPasswordHasher(string|PasswordAuthenticatedUserInterface|PasswordHasherAwareInterface $user): PasswordHasherInterface
            {
                return new readonly class($this->factory->getPasswordHasher($user), $this->work) implements PasswordHasherInterface {
                    /** @param \ArrayObject<string, int> $work */
                    public function __construct(private PasswordHasherInterface $hasher, private \ArrayObject $work)
                    {
                    }

                    public function hash(#[\SensitiveParameter] string $plainPassword): string
                    {
                        $this->work['hash'] = ($this->work['hash'] ?? 0) + 1;

                        return $this->hasher->hash($plainPassword);
                    }

                    public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword): bool
                    {
                        $this->work['verify'] = ($this->work['verify'] ?? 0) + 1;

                        return $this->hasher->verify($hashedPassword, $plainPassword);
                    }

                    public function needsRehash(string $hashedPassword): bool
                    {
                        return $this->hasher->needsRehash($hashedPassword);
                    }
                };
            }
        };
    }
}
