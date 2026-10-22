<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Content\Internal\Row;
use Quire\User\CreateUser;
use Quire\User\Exception\InvalidUserException;
use Quire\User\Role;
use Quire\User\UpdateUser;
use Quire\User\User;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

final class UserWriterTest extends KernelTestCase
{
    private Connection $connection;

    private UserWriter $writer;

    private UserRepository $users;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->writer = self::getContainer()->get(UserWriter::class);
        $this->users = self::getContainer()->get(UserRepository::class);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testCreatesAUserWithAHashedPassword(): void
    {
        $user = $this->writer->create(new CreateUser('Ada Lovelace', 'Ada@Example.com', password: 'correct horse', roles: [Role::EDITOR, Role::ADMIN, Role::EDITOR]));

        self::assertTrue(Row::isUuid($user->id));
        self::assertSame(['Ada@Example.com', 'ada', 'Ada Lovelace'], [$user->email, $user->username, $user->displayName]);
        self::assertSame(['admin', 'editor'], $user->roles, 'Sorted, without duplicates.');
        self::assertSame(User::ACTIVE, $user->status);
        self::assertNotSame('correct horse', $user->passwordHash);
        self::assertTrue(self::getContainer()->get(PasswordHasherFactoryInterface::class)->getPasswordHasher(User::class)->verify((string) $user->passwordHash, 'correct horse'));
        self::assertSame(['ROLE_USER', 'ROLE_ADMIN', 'ROLE_EDITOR'], $user->getRoles());
        self::assertSame($user->id, $user->getUserIdentifier(), 'Symfony identifies users by ID, which doesn\'t change.');
    }

    public function testUsernamesAreDerivedAndUnique(): void
    {
        $first = $this->writer->create(new CreateUser('Grace Hopper', 'grace.hopper@example.com'));
        $second = $this->writer->create(new CreateUser('Grace Hopper', 'grace.hopper@example.org'));
        $third = $this->writer->create(new CreateUser('Ümit Özdemir'));

        self::assertSame(['grace-hopper', 'grace-hopper-2', 'umit-ozdemir'], [$first->username, $second->username, $third->username]);

        $this->assertInvalid(['username' => 'The username "grace-hopper" is taken.'], fn (): User => $this->writer->create(new CreateUser('Grace', username: 'Grace-Hopper')));
    }

    public function testRefusesInvalidUsers(): void
    {
        $this->writer->create(new CreateUser('Ada', 'ada@example.com'));

        $this->assertInvalid(['email' => 'Another user has this email.'], fn (): User => $this->writer->create(new CreateUser('Ada 2', 'ADA@example.com')));
        $this->assertInvalid([
            'displayName' => 'The display name must be 1 to 100 characters long.',
            'email' => '"not-an-email" isn\'t a valid email address.',
            'roles' => 'Unknown role "wizard". Roles: member, editor, admin.',
            'password' => 'Passwords must be at least 8 characters long.',
            'username' => 'Usernames are lowercase letters, digits and single hyphens, at most 64 characters, e.g. "ada-lovelace".',
        ], fn (): User => $this->writer->create(new CreateUser(' ', 'not-an-email', username: 'no spaces', password: 'short', roles: ['wizard'])));
    }

    public function testChangesProfilePasswordRolesAndStatus(): void
    {
        $ada = $this->writer->create(new CreateUser('Ada', 'ada@example.com', password: 'first password'));
        $grace = $this->writer->create(new CreateUser('Grace', 'grace@example.com'));

        $ada = $this->writer->update(new UpdateUser($ada->id)->displayName('Ada L.')->username('countess')->attributes(['bio' => 'Mathematician']));
        self::assertSame(['Ada L.', 'countess', ['bio' => 'Mathematician']], [$ada->displayName, $ada->username, $ada->attributes]);
        $this->assertInvalid(['username' => 'The username "grace" is taken.'], fn (): User => $this->writer->update(new UpdateUser($ada->id)->username('grace')));
        $this->assertInvalid(['email' => 'Another user has this email.'], fn (): User => $this->writer->update(new UpdateUser($ada->id)->email('Grace@example.com')));

        $hash = $ada->passwordHash;
        self::assertNotSame($hash, $this->writer->changePassword($ada->id, 'second password')->passwordHash);
        self::assertNull($this->writer->changePassword($ada->id, null)->passwordHash);

        self::assertSame(['editor', 'member'], $this->writer->setRoles($grace->id, ['member', 'editor'])->roles);
        self::assertSame(User::SUSPENDED, $this->writer->suspend($grace->id)->status);
        self::assertTrue($this->writer->activate($grace->id)->isActive());
    }

    public function testFindsUsersByIdEmailUsernameOrIdentity(): void
    {
        $ada = $this->writer->create(new CreateUser('Ada', 'ada@example.com'));
        $this->writer->linkIdentity($ada->id, 'github', '42', ['login' => 'ada']);

        foreach ([$ada->id, 'ADA@example.com', 'Ada'] as $reference) {
            self::assertSame($ada->id, $this->users->findByReference($reference)?->id, $reference);
        }

        self::assertSame($ada->id, $this->users->findByIdentity('github', '42')?->id);
        self::assertNull($this->users->findByIdentity('github', '43'));
        self::assertNull($this->users->findByReference('nobody'));

        $grace = $this->writer->create(new CreateUser('Grace', 'grace@example.com'));
        $this->assertInvalid(['identity' => 'This github identity is linked to another user.'], fn () => $this->writer->linkIdentity($grace->id, 'github', '42'));

        $this->writer->unlinkIdentity($ada->id, 'github', '42');
        self::assertNull($this->users->findByIdentity('github', '42'));
    }

    public function testListsAndCountsUsersByRole(): void
    {
        $this->writer->create(new CreateUser('Ada', 'ada@example.com', roles: [Role::ADMIN]));
        $this->writer->create(new CreateUser('Grace', 'grace@example.com', roles: [Role::EDITOR]));
        $this->writer->create(new CreateUser('Edsger', 'edsger@example.com', roles: [Role::EDITOR]));

        self::assertSame(['Grace', 'Edsger'], array_map(static fn (User $user): string => $user->displayName, $this->users->all(Role::EDITOR)));
        self::assertSame(2, $this->users->count(Role::EDITOR));
        self::assertSame(1, $this->users->count(Role::ADMIN));
    }

    /**
     * @param array<string, string> $violations
     */
    private function assertInvalid(array $violations, \Closure $operation): void
    {
        try {
            $operation();
            self::fail('The user was accepted.');
        } catch (InvalidUserException $e) {
            self::assertEquals($violations, $e->violations);
        }
    }
}
