<?php

declare(strict_types=1);

namespace Quire\Admin\Preferences;

use Quire\Audit\AuditLog;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * "Under the hood": whether the admin shows what's behind it, i.e. the console commands of actions, IDs
 * and keyboard hints. Each person switches it; until they do, it's on for those who may see the audit
 * log (admins) and off for others.
 *
 * Kept in a cookie for now; it moves to people's preferences when the admin has them.
 *
 * @api
 */
final readonly class UnderTheHood
{
    public const string COOKIE = 'quire_admin_hood';

    public function __construct(
        private RequestStack $requests,
        private Security $security,
    ) {
    }

    public function isOn(?Request $request = null): bool
    {
        $chosen = ($request ?? $this->requests->getMainRequest())?->cookies->get(self::COOKIE);

        return match ($chosen) {
            '1' => true,
            '0' => false,
            default => $this->security->isGranted(AuditLog::ABILITY),
        };
    }

    public function cookie(bool $on, string $path): Cookie
    {
        return Cookie::create(self::COOKIE, $on ? '1' : '0', new \DateTimeImmutable('+1 year'), $path, httpOnly: true, sameSite: Cookie::SAMESITE_LAX);
    }
}
