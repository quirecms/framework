<?php

declare(strict_types=1);

namespace Quire\Admin\Preferences;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * How much space lists take: "comfortable" (the default) or "compact", which fits more rows on a
 * screen. Kept in a cookie for now; with per-person preferences, it moves to them.
 *
 * @internal
 */
final readonly class Density
{
    public const string COOKIE = 'quire_admin_density';

    public const array CHOICES = ['comfortable', 'compact'];

    public function __construct(
        private RequestStack $requests,
    ) {
    }

    public function get(?Request $request = null): string
    {
        $chosen = ($request ?? $this->requests->getMainRequest())?->cookies->get(self::COOKIE);

        return \in_array($chosen, self::CHOICES, true) ? $chosen : 'comfortable';
    }

    public function cookie(string $density, string $path): Cookie
    {
        return Cookie::create(self::COOKIE, \in_array($density, self::CHOICES, true) ? $density : 'comfortable', new \DateTimeImmutable('+1 year'), $path, httpOnly: true, sameSite: Cookie::SAMESITE_LAX);
    }
}
