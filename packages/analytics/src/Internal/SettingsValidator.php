<?php

declare(strict_types=1);

namespace Quire\Analytics\Internal;

use Quire\Analytics\AnalyticsSettings;
use Quire\Settings\Settings;
use Quire\Settings\SettingValidatorInterface;

/**
 * @internal
 */
final readonly class SettingsValidator implements SettingValidatorInterface
{
    public function validate(string $key, mixed $value, Settings $settings): ?string
    {
        return match ($key) {
            AnalyticsSettings::GA4_ID => \is_string($value) && ('' === trim($value) || 1 === preg_match(AnalyticsSettings::GA4_FORMAT, trim($value))) ? null : 'A Google Analytics 4 measurement ID is "G-" and capital letters and digits, e.g. "G-ABC123DEF4" (in Google Analytics: Admin, Data streams, the site\'s stream). Leave it empty to turn Google Analytics off.',
            AnalyticsSettings::GA4_KEEP => \is_string($value) && array_all(array_filter(array_map(trim(...), explode(',', $value)), static fn (string $name): bool => '' !== $name), static fn (string $name): bool => 1 === preg_match(AnalyticsSettings::PARAMETER_FORMAT, $name)) ? null : 'Names of query parameters, separated by commas, e.g. "q, page".',
            default => null,
        };
    }
}
