<?php

declare(strict_types=1);

namespace Quire\Privacy;

use Quire\Settings\Settings;

/**
 * The owner's settings of "Privacy and cookies", read in one place.
 *
 * @api
 */
final readonly class PrivacySettings
{
    public const string LAYOUT = 'quire-privacy.layout';
    public const string REJECT_WORDING = 'quire-privacy.reject_wording';
    public const string CHOICE_MONTHS = 'quire-privacy.choice_months';
    public const string ASK_AGAIN_MONTHS = 'quire-privacy.ask_again_months';
    public const string HONOUR_SIGNALS = 'quire-privacy.honour_signals';
    public const string PRIVACY_PAGE = 'quire-privacy.privacy_page';
    public const string SEND_IN_TEST = 'quire-privacy.send_in_test';
    public const string LOG_MONTHS = 'quire-privacy.log_months';

    /** The longest choices are kept as proof, in months */
    public const int MAX_LOG_MONTHS = 60;

    /** The longest a choice is kept, in months: regulators expect visitors to be asked again within a year */
    public const int MAX_MONTHS = 12;

    public function __construct(private Settings $settings)
    {
    }

    /**
     * "bar" (at the bottom, the default) or "corner" (a box).
     */
    public function layout(): string
    {
        return 'corner' === $this->settings->getString(self::LAYOUT) ? 'corner' : 'bar';
    }

    /**
     * How "reject" reads: "reject" ("Reject …"), "necessary" ("Necessary only") or "technical"
     * ("Technical cookies only"). They mean the same.
     */
    public function rejectWording(): string
    {
        $wording = $this->settings->getString(self::REJECT_WORDING);

        return \in_array($wording, ['necessary', 'technical'], true) ? $wording : 'reject';
    }

    /** How long a choice that allows something is kept */
    public function choiceMonths(): int
    {
        return max(1, min(self::MAX_MONTHS, $this->settings->getInt(self::CHOICE_MONTHS)));
    }

    /** How soon visitors who allowed nothing are asked again */
    public function askAgainMonths(): int
    {
        return max(1, min(self::MAX_MONTHS, $this->settings->getInt(self::ASK_AGAIN_MONTHS)));
    }

    /** Whether Global Privacy Control and Do Not Track count as "reject" (the default) */
    public function honourSignals(): bool
    {
        return $this->settings->getBool(self::HONOUR_SIGNALS);
    }

    /** How long logged choices are kept as proof */
    public function logMonths(): int
    {
        return max(1, min(self::MAX_LOG_MONTHS, $this->settings->getInt(self::LOG_MONTHS)));
    }

    /**
     * Whether services run after consent outside production and on other domains than the site's own
     * too, e.g. to check a setup on a temporary domain (off: they never do there).
     */
    public function sendInTest(): bool
    {
        return $this->settings->getBool(self::SEND_IN_TEST);
    }

    /** The site's privacy page, linked from the settings dialog; null without one */
    public function privacyPage(): ?string
    {
        $page = trim($this->settings->getString(self::PRIVACY_PAGE));

        return '' === $page ? null : $page;
    }
}
