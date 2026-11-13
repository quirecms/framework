<?php

declare(strict_types=1);

use Quire\Analytics\AnalyticsSettings;
use Quire\Analytics\Internal\NotCounted;
use Quire\Extend;
use Quire\Privacy\Category;
use Quire\Privacy\Cookie;
use Quire\Privacy\Extend\Privacy;

/*
 * Analytics: Google Analytics 4 through quire/privacy, so visitors are asked first. It's declared here
 * (what can run: its hosts, cookies, script), and runs while the owner has set its measurement ID in
 * the admin's settings ("Analytics").
 */
return [
    new Extend\Services()
        ->autoload('Quire\\Analytics\\', __DIR__.'/src', exclude: [
            __DIR__.'/src/AnalyticsSettings.php',
        ]),

    new Privacy()
        ->service(
            AnalyticsSettings::GA4_SERVICE,
            label: 'Google Analytics',
            category: Category::Statistics,
            vendor: 'Google Ireland Limited',
            purpose: 'Counts visits and shows how the site is used, for its owner. Google may process the data in the United States (Google LLC).',
            cookies: [
                new Cookie('_ga', '2 years', 'Tells visits of the same browser apart.'),
                new Cookie('_ga_*', '2 years', 'Keeps the state of the visit.'),
            ],
            // Google's hosts for GA4 (developers.google.com/tag-platform/security/guides/csp). Scripts:
            // gtag.js and what it loads (/gtag/js, /gtag/destination), never a Tag Manager container
            // (/gtm.js), which could run any script: while it's on, the policy allows these for every
            // visitor, so it must not allow more than the tag. Anything else a tag configuration on
            // Google's side adds (ads, other destinations) isn't declared, so the policy blocks it.
            hosts: [
                'script-src' => ['https://www.googletagmanager.com/gtag/'],
                'connect-src' => ['https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://*.googletagmanager.com'],
                'img-src' => ['https://*.google-analytics.com', 'https://*.googletagmanager.com'],
            ],
            retention: 'As set in Google Analytics: 2 or 14 months',
            policyUrl: 'https://policies.google.com/privacy',
            script: 'quire-analytics/ga4.js',
            // Off until the owner sets the measurement ID (Internal\Ga4Switch).
            switched: true,
            runtime: ['measurement_id', 'keep'],
        ),

    new Extend\Settings()
        ->define(AnalyticsSettings::GA4_ID, 'string', '', label: 'Google Analytics measurement ID', help: 'From Google Analytics: Admin, Data streams, the site\'s stream, e.g. "G-ABC123DEF4". Visitors are asked first. Empty: Google Analytics is off.', ability: AnalyticsSettings::ABILITY)
        ->define(AnalyticsSettings::GA4_KEEP, 'string', '', label: 'Query parameters Google Analytics may see', help: 'Besides campaigns (utm_…), separated by commas, e.g. "q" for the site\'s search. Others are left out of the addresses sent: links to sign in or reset a password carry tokens and e-mail addresses.', ability: AnalyticsSettings::ABILITY),

    new Extend\Permissions()
        ->ability(AnalyticsSettings::ABILITY, 'Set up analytics', roles: [], group: 'admin'),

    // Previews, a theme being tried and error pages aren't counted.
    new Extend\Views()
        ->slot('head', NotCounted::class),
];
