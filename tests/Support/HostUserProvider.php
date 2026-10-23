<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * The users of {@see HostAppKernel}, as if from its database: tests change their roles in $roles.
 *
 * @implements UserProviderInterface<InMemoryUser>
 */
final class HostUserProvider implements UserProviderInterface
{
    /** @var array<string, list<string>> Identifier => Symfony roles */
    public static array $roles = [];

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return isset(self::$roles[$identifier]) ? new InMemoryUser($identifier, null, self::$roles[$identifier]) : throw new UserNotFoundException();
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $user instanceof InMemoryUser ? $this->loadUserByIdentifier($user->getUserIdentifier()) : throw new UnsupportedUserException();
    }

    public function supportsClass(string $class): bool
    {
        return InMemoryUser::class === $class;
    }
}
