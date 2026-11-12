<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal\Theme;

use Quire\Privacy\Category;
use Quire\Privacy\Cookie;
use Quire\Privacy\PrivacySettings;
use Quire\Privacy\Service;
use Quire\Privacy\Services;
use Quire\Theme\ThemeAssets;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * What the banner, the settings dialog and the consent loader are given: the same for every visitor
 * of a host, so pages stay cacheable. The visitor's own choice is read by the loader, in the browser.
 *
 * @internal
 */
final readonly class Banner
{
    /** Request attribute: the "Cookie settings" opener was printed on this page already */
    public const string OPENER_ATTRIBUTE = '_quire_privacy_opener';

    public function __construct(
        private Services $services,
        private PrivacySettings $settings,
        private ThemeAssets $assets,
        private RequestStack $requests,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
        #[Autowire(param: 'router.request_context.base_url')]
        private string $siteUri = '',
    ) {
    }

    public function shown(): bool
    {
        return $this->services->needConsent();
    }

    /**
     * Whether nothing is sent: outside production, or on a host other than the site's own address
     * (a temporary domain, a preview). Visitors are still asked, and told.
     */
    public function testMode(): bool
    {
        if ('prod' !== $this->environment) {
            return true;
        }

        $site = parse_url($this->siteUri, \PHP_URL_HOST);
        $request = $this->requests->getMainRequest();

        if (!\is_string($site) || '' === $site || 'localhost' === $site || !$request instanceof Request) {
            return false;
        }

        return strtolower($request->getHost()) !== strtolower($site);
    }

    /**
     * "single" with one category (its own question, e.g. "Accept analytics cookies"), or "several"
     * (accept all, reject all, choose).
     */
    public function mode(): string
    {
        return 1 === \count($this->services->categories()) ? 'single' : 'several';
    }

    /**
     * @return array{mode: string, category: Category, layout: string, reject: string, test: bool, privacy_page: ?string, categories: list<array{category: Category, services: list<Service>}>}
     */
    public function view(): array
    {
        $categories = [];

        foreach ($this->services->categories() as $category) {
            $categories[] = ['category' => $category, 'services' => array_values(array_filter($this->services->askedAbout(), static fn (Service $service): bool => $category === $service->category))];
        }

        return [
            'mode' => $this->mode(),
            'category' => $this->services->categories()[0] ?? Category::Statistics,
            'layout' => $this->settings->layout(),
            'reject' => $this->settings->rejectWording(),
            'test' => $this->testMode(),
            'privacy_page' => $this->settings->privacyPage(),
            'categories' => $categories,
        ];
    }

    /**
     * What the loader reads (a JSON data block, which no Content-Security-Policy treats as a script).
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return [
            'v' => 1,
            'revision' => $this->services->revision(),
            'test' => $this->testMode(),
            'signals' => $this->settings->honourSignals(),
            'months' => ['choice' => $this->settings->choiceMonths(), 'again' => $this->settings->askAgainMonths()],
            'cookie' => ['name' => 'quire_consent', 'secure' => 'https' === $this->requests->getMainRequest()?->getScheme()],
            'services' => array_map(fn (Service $service): array => [
                'id' => $service->id,
                'label' => $service->label,
                'category' => $service->category->value,
                'script' => null === $service->script ? null : $this->assets->url($service->script),
                'config' => (object) $service->config,
                'cookies' => array_map(static fn (Cookie $cookie): string => $cookie->name, $service->cookies),
            ], $this->services->askedAbout()),
        ];
    }

    public function loaderUrl(): string
    {
        return $this->assets->url('quire-privacy/consent.js');
    }

    public function stylesheetUrl(): string
    {
        return $this->assets->url('quire-privacy/consent.css');
    }

    /**
     * Whether the opener is still to be printed on this page: once, by the theme's
     * quire_consent_settings() or, without it, in the footer.
     */
    public function takeOpener(): bool
    {
        $request = $this->requests->getMainRequest();

        if (!$this->shown() || !$request instanceof Request || true === $request->attributes->get(self::OPENER_ATTRIBUTE)) {
            return false;
        }

        $request->attributes->set(self::OPENER_ATTRIBUTE, true);

        return true;
    }
}
