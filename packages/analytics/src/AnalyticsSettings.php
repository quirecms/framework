<?php

declare(strict_types=1);

namespace Quire\Analytics;

/**
 * quire/analytics' settings and names: Google Analytics 4, turned on by its measurement ID, which the
 * site's owner sets in the admin ("Analytics").
 *
 * @api
 */
final class AnalyticsSettings
{
    /** The measurement ID ("G-…"); empty: Google Analytics is off */
    public const string GA4_ID = 'quire-analytics.ga4_id';

    /** Query parameters Google Analytics may see besides utm_*, comma-separated, e.g. "q" */
    public const string GA4_KEEP = 'quire-analytics.ga4_keep';

    /** Changing them, besides managing settings: it sends every visitor's data to another property */
    public const string ABILITY = 'quire-analytics.manage';

    /** The ID of the service in quire/privacy */
    public const string GA4_SERVICE = 'google-analytics';

    /** Google's format of a GA4 measurement ID, "G-" and capital letters and digits (support.google.com/analytics/answer/12270356) */
    public const string GA4_FORMAT = '/^G-[A-Z0-9]{4,}$/';

    /** A query parameter's name */
    public const string PARAMETER_FORMAT = '/^[A-Za-z0-9_.-]{1,40}$/';

    private function __construct()
    {
    }
}
