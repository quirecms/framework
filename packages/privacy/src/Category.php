<?php

declare(strict_types=1);

namespace Quire\Privacy;

/**
 * What a service is for, which is what visitors choose by. Close to the WP Consent API's categories,
 * which WordPress plugins share.
 *
 * @api
 */
enum Category: string
{
    /** What the visitor asked for can't work without it, e.g. signing in: never asked about, always on */
    case Necessary = 'necessary';

    /** Remembers the visitor's choices, e.g. a font service or a map's settings */
    case Preferences = 'preferences';

    /**
     * The site's own measurement, aggregated, with no reuse by its provider. Kept apart so it can be
     * exempt where the law allows it; until then it's asked about like the others, and starts off.
     */
    case OwnStatistics = 'statistics-own';

    /** Analytics, e.g. Google Analytics (visitors see "Analytics") */
    case Statistics = 'statistics';

    /** Advertising, and services that follow visitors across sites, e.g. YouTube videos */
    case Marketing = 'marketing';

    public function needsConsent(): bool
    {
        return self::Necessary !== $this;
    }

    /**
     * Its name for visitors, to translate in the "quire-privacy" domain.
     */
    public function label(): string
    {
        return match ($this) {
            self::Necessary => 'Necessary',
            self::Preferences => 'Preferences',
            self::OwnStatistics => 'Own measurement',
            self::Statistics => 'Analytics',
            self::Marketing => 'Marketing',
        };
    }

    /**
     * One sentence for visitors on what it covers, to translate in the "quire-privacy" domain.
     */
    public function description(): string
    {
        return match ($this) {
            self::Necessary => 'Needed for the site to work, e.g. to sign in. Always on.',
            self::Preferences => 'Remember your choices, e.g. fonts or a map\'s settings.',
            self::OwnStatistics => 'Count visits for this site only, without sharing them.',
            self::Statistics => 'Help the site see how it\'s used, through an analytics service.',
            self::Marketing => 'Videos and other content from companies that may follow you across sites.',
        };
    }
}
