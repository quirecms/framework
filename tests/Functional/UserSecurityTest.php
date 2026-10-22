<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Quire\User\CreateUser;
use Quire\User\Security\UserChecker;
use Quire\User\Security\UserProvider;
use Quire\User\User;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Quire's users in Symfony's security: the provider, the checker, and what happens to signed-in users.
 */
final class UserSecurityTest extends WebTestCase
{
    private KernelBrowser $client;

    private Connection $connection;

    private UserWriter $writer;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->writer = self::getContainer()->get(UserWriter::class);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testTheProviderLoadsUsersByIdEmailOrUsername(): void
    {
        $ada = $this->writer->create(new CreateUser('Ada', 'ada@example.com'));
        $provider = self::getContainer()->get(UserProvider::class);

        foreach ([$ada->id, 'ada@example.com', 'ada'] as $identifier) {
            self::assertSame($ada->id, $provider->loadUserByIdentifier($identifier)->id);
        }

        $this->expectException(UserNotFoundException::class);
        $provider->loadUserByIdentifier('nobody@example.com');
    }

    public function testTheProviderStoresUpgradedHashesAndRefusesOtherUsers(): void
    {
        $ada = $this->writer->create(new CreateUser('Ada', 'ada@example.com', password: 'correct horse'));
        $provider = self::getContainer()->get(UserProvider::class);

        $provider->upgradePassword($ada, 'new-hash');
        self::assertSame('new-hash', $provider->refreshUser($ada)->passwordHash);
        self::assertFalse($provider->supportsClass(InMemoryUser::class));

        $this->expectException(UnsupportedUserException::class);
        $provider->refreshUser(new InMemoryUser('someone', null));
    }

    public function testSuspendedUsersCantSignIn(): void
    {
        $grace = $this->writer->suspend($this->writer->create(new CreateUser('Grace', 'grace@example.com'))->id);

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessageIsOrContains('This account is suspended.');
        new UserChecker()->checkPostAuth($grace);
    }

    public function testASignedInUserStaysSignedIn(): void
    {
        $this->client->loginUser($this->writer->create(new CreateUser('Ada', 'ada@example.com', password: 'correct horse')));

        $this->client->request('GET', '/_fixture/whoami');
        self::assertResponseIsSuccessful();
        self::assertSame('ada', $this->client->getResponse()->getContent());

        $this->client->request('GET', '/_fixture/whoami');
        self::assertSame('ada', $this->client->getResponse()->getContent());
    }

    public function testSuspendingOrChangingThePasswordSignsTheUserOut(): void
    {
        foreach (['suspend' => fn (User $user): User => $this->writer->suspend($user->id), 'password' => fn (User $user): User => $this->writer->changePassword($user->id, 'another password')] as $change => $apply) {
            $user = $this->writer->create(new CreateUser('Ada', $change.'@example.com', password: 'correct horse'));
            $this->client->loginUser($user);
            $this->client->request('GET', '/_fixture/whoami');
            self::assertNotSame('anonymous', $this->client->getResponse()->getContent());

            $apply($user);
            $this->client->request('GET', '/_fixture/whoami');

            self::assertSame('anonymous', $this->client->getResponse()->getContent(), $change);
        }
    }

    public function testTheSessionDoesntHoldThePasswordHash(): void
    {
        $ada = $this->writer->create(new CreateUser('Ada', 'ada@example.com', password: 'correct horse'));

        $restored = unserialize(serialize($ada));

        self::assertInstanceOf(User::class, $restored);
        self::assertSame(hash('crc32c', (string) $ada->passwordHash), $restored->passwordHash);
        self::assertSame([$ada->id, $ada->email, $ada->roles], [$restored->id, $restored->email, $restored->roles]);
    }
}
