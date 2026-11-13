<?php

declare(strict_types=1);

namespace Quire\Privacy;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Turns a declared service on or off while the site runs, and fills in what its declaration leaves to
 * then, e.g. Google Analytics with the measurement ID an owner sets in the admin. The declaration (at
 * build) stays the only source of what can run: its hosts, cookies, category and script. A switch can
 * only narrow it (off) and give it scalars, for the keys the declaration names as runtime keys.
 *
 * Only the package that declared the service may switch it, once: the build fails otherwise, and the
 * site ignores such a switch. If a switch fails, its service is off for that request.
 *
 * @internal First-party only for now (quire/analytics); made @api once a third-party provider needs it
 */
#[AutoconfigureTag(self::TAG)]
interface ServiceSwitchInterface
{
    public const string TAG = 'quire_privacy.service_switch';

    /** The ID of the declared service it switches */
    public static function service(): string;

    /** Whether the service runs, e.g. because the owner set its ID */
    public function enabled(): bool;

    /**
     * Values for the declaration's runtime keys: scalars. Another key, or a value that isn't a scalar,
     * leaves the service off.
     *
     * @return array<string, mixed>
     */
    public function config(): array;
}
