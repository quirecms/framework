<?php

declare(strict_types=1);

namespace Quire\Admin\Preferences;

use Quire\Audit\AuditLog;
use Quire\User\Preference\UserPreferences;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * "Under the hood": whether the admin shows what's behind it, i.e. the console commands of actions, IDs
 * and keyboard hints. Each person switches it, as their preference "quire-admin.hood"; until they do,
 * it's on for those who may see the audit log (admins) and off for others.
 *
 * @api
 */
final readonly class UnderTheHood
{
    public function __construct(
        private UserPreferences $preferences,
        private Security $security,
    ) {
    }

    public function isOn(): bool
    {
        $chosen = $this->preferences->mine(AdminPreferences::HOOD);

        return \is_bool($chosen) ? $chosen : $this->security->isGranted(AuditLog::ABILITY);
    }
}
