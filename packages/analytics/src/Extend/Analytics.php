<?php

declare(strict_types=1);

namespace Quire\Analytics\Extend;

use Quire\Extend\ExtenderContext;
use Quire\Extend\ExtenderInterface;
use Quire\Privacy\Category;
use Quire\Privacy\Cookie;
use Quire\Privacy\Extend\Privacy;

/**
 * The site's analytics, in its own extend.php: Google Analytics 4, declared as a service of
 * quire/privacy, so visitors are asked first (analytics), the site's Content-Security-Policy allows
 * Google's hosts, and the privacy notice lists it.
 *
 *     new Quire\Analytics\Extend\Analytics()->ga4('G-ABC123DEF4'),
 *
 * Only the site may: a theme or an extension is shared by many sites, and its measurement ID would
 * send all their visitors to one Google Analytics property.
 *
 * @api
 */
final class Analytics implements ExtenderInterface
{
    /** The ID of the service in quire/privacy */
    public const string GA4 = 'google-analytics';

    /** Google's format of a GA4 measurement ID, "G-" and letters and digits (support.google.com/analytics/answer/12270356) */
    private const string MEASUREMENT_ID = '/^G-[A-Z0-9]{4,}$/';

    private ?string $measurementId = null;

    /** @var list<string> */
    private array $keep = [];

    /**
     * @param list<string> $keepParameters Query parameters Google Analytics may see in the page's address
     *                                     and the referrer, besides "utm_*" (campaigns), e.g. a search's
     *                                     "q". Others are left out: sign-in, reset and other links carry
     *                                     tokens and e-mail addresses in them
     */
    public function ga4(string $measurementId, array $keepParameters = []): self
    {
        if (1 !== preg_match(self::MEASUREMENT_ID, $measurementId)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a Google Analytics 4 measurement ID: it\'s "G-" and capital letters and digits, e.g. "G-ABC123DEF4" (in Google Analytics: Admin, Data streams, the site\'s stream).', $measurementId));
        }

        foreach ($keepParameters as $parameter) {
            if (1 !== preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $parameter)) {
                throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a query parameter\'s name.', $parameter));
            }
        }

        $this->measurementId = $measurementId;
        $this->keep = array_values(array_unique($keepParameters));

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        if (null === $this->measurementId) {
            return;
        }

        if (ExtenderContext::SITE !== $context->id) {
            throw $context->error('Analytics::ga4()', 'Only the site\'s own extend.php sets the Google Analytics measurement ID: a theme or extension is used by many sites, and its ID would send all their visitors to one property.');
        }

        if (!$context->isEnabled('quire-analytics')) {
            throw $context->error('Analytics::ga4()', 'Enable quire/analytics first: bin/console quire:extension:enable quire-analytics.');
        }

        // Google's hosts for GA4 (developers.google.com/tag-platform/security/guides/csp): the tag, and
        // where hits go. Anything else a tag configuration on Google's side adds (ads, other
        // destinations) isn't declared, so the policy blocks it.
        new Privacy()
            ->service(
                self::GA4,
                label: 'Google Analytics',
                category: Category::Statistics,
                vendor: 'Google Ireland Limited',
                purpose: 'Counts visits and shows how the site is used, for its owner. Google may process the data in the United States (Google LLC).',
                cookies: [
                    new Cookie('_ga', '2 years', 'Tells visits of the same browser apart.'),
                    new Cookie('_ga_*', '2 years', 'Keeps the state of the visit.'),
                ],
                hosts: [
                    'script-src' => ['https://*.googletagmanager.com'],
                    'connect-src' => ['https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://*.googletagmanager.com'],
                    'img-src' => ['https://*.google-analytics.com', 'https://*.googletagmanager.com'],
                ],
                retention: 'As set in Google Analytics: 2 or 14 months',
                policyUrl: 'https://policies.google.com/privacy',
                script: 'quire-analytics/ga4.js',
                config: ['measurement_id' => $this->measurementId, 'keep' => implode(',', $this->keep)],
            )
            ->extend($context);
    }
}
