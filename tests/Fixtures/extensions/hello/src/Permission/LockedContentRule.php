<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Permission;

use Quire\Content\Content;
use Quire\Permission\AbilityRuleInterface;
use Quire\User\User;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses every ability on items titled "Locked", even to admins, and lets the user "helper" moderate
 * greetings whatever their roles.
 */
final class LockedContentRule implements AbilityRuleInterface
{
    public function decide(string $ability, ?UserInterface $user, mixed $subject): ?bool
    {
        if ($subject instanceof Content && 'Locked' === $subject->title) {
            return false;
        }

        if ('quire-fixture-hello.moderate' === $ability && $user instanceof User && 'helper' === $user->username) {
            return true;
        }

        return null;
    }
}
