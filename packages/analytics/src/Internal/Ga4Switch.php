<?php

declare(strict_types=1);

namespace Quire\Analytics\Internal;

use Quire\Analytics\AnalyticsSettings;
use Quire\Privacy\ServiceSwitchInterface;
use Quire\Settings\Settings;

/**
 * Google Analytics runs while the owner has set a valid measurement ID; its ID and the parameters it may
 * see come from the settings. Anything else leaves it off: no banner question, no hosts, no notice
 * entry.
 *
 * @internal
 */
final readonly class Ga4Switch implements ServiceSwitchInterface
{
    public function __construct(private Settings $settings)
    {
    }

    public static function service(): string
    {
        return AnalyticsSettings::GA4_SERVICE;
    }

    public function enabled(): bool
    {
        return 1 === preg_match(AnalyticsSettings::GA4_FORMAT, $this->id());
    }

    public function config(): array
    {
        $keep = array_filter(array_map(trim(...), explode(',', $this->settings->getString(AnalyticsSettings::GA4_KEEP))), static fn (string $name): bool => 1 === preg_match(AnalyticsSettings::PARAMETER_FORMAT, $name));

        return ['measurement_id' => $this->id(), 'keep' => implode(',', array_unique($keep))];
    }

    private function id(): string
    {
        return trim($this->settings->getString(AnalyticsSettings::GA4_ID));
    }
}
