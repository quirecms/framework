<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal;

use Quire\Privacy\PrivacySettings;
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
            PrivacySettings::CHOICE_MONTHS, PrivacySettings::ASK_AGAIN_MONTHS => \is_int($value) && $value >= 1 && $value <= PrivacySettings::MAX_MONTHS ? null : \sprintf('From 1 to %d months: visitors are asked again within a year.', PrivacySettings::MAX_MONTHS),
            PrivacySettings::PRIVACY_PAGE => !\is_string($value) || '' === $value || 1 === preg_match('#^(/[^\s"<>]*|https://[^\s"<>]+)$#', $value) ? null : 'A path on the site, e.g. "/privacy", or an https:// address.',
            default => null,
        };
    }
}
