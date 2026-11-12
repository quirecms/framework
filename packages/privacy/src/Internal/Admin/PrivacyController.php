<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal\Admin;

use Quire\Privacy\ConsentLog;
use Quire\Privacy\Internal\Theme\Banner;
use Quire\Privacy\PrivacySettings;
use Quire\Privacy\Service;
use Quire\Privacy\Services;
use Quire\Theme\ContentPreviews;
use Quire\Theme\ThemeAssets;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Twig\Environment;

/**
 * The owner's "Privacy and cookies": what visitors see, in words; the services, with what visitors
 * answered lately; necessary services that reach other sites, to check; a preview of the banner and the
 * settings in the site's look; and a visitor's choices by their ID, with what they were told.
 *
 * @internal
 */
final readonly class PrivacyController
{
    public const string ABILITY = 'quire-privacy.manage';
    public const string ROUTE = 'quire_privacy_admin';

    public function __construct(
        private Security $security,
        private Environment $twig,
        private Services $services,
        private PrivacySettings $settings,
        private Banner $banner,
        private ConsentLog $log,
        private ContentPreviews $previews,
        private ThemeAssets $assets,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (!$this->security->isGranted(self::ABILITY)) {
            throw new AccessDeniedHttpException('Managing privacy and cookies takes the right to do so.');
        }

        $locale = $request->getLocale();
        $consentId = strtolower(trim($request->query->getString('consent')));
        $choices = 1 === preg_match('/^[0-9a-f]{32}$/', $consentId) ? $this->log->of($consentId) : null;
        $groups = [];

        foreach ($this->services->categories() as $category) {
            $groups[] = ['category' => $category, 'services' => array_values(array_filter($this->services->askedAbout(), static fn (Service $service): bool => $category === $service->category))];
        }

        return new Response($this->twig->render('@quire_privacy/admin/privacy.html.twig', [
            'shown' => $this->banner->shown(),
            'test' => $this->banner->testMode(),
            'mode' => $this->banner->mode(),
            'groups' => $groups,
            // Necessary services run without asking: one that reaches another site is listed apart, for
            // the owner to check that the site really can't work without it.
            'necessary' => array_values(array_filter($this->services->all(), static fn (Service $service): bool => !$service->category->needsConsent() && [] !== $service->hosts)),
            'totals' => $this->log->totals(new \DateTimeImmutable('-30 days')),
            'privacy_page' => $this->settings->privacyPage(),
            'log_months' => $this->settings->logMonths(),
            'previews' => $this->banner->shown() ? $this->previews($locale) : [],
            'consent_id' => '' === $consentId ? null : $consentId,
            'choices' => $choices,
        ]));
    }

    /**
     * The first layer and the settings, as visitors see them, in the site's look, without scripts.
     *
     * @return array{banner: string, dialog: string}
     */
    private function previews(string $locale): array
    {
        $html = $this->twig->render('@quire_privacy/consent.html.twig', [...$this->banner->view(), 'config' => [], 'loader' => '', 'preview' => true]);
        $stylesheets = [$this->assets->url('quire-privacy/consent.css'), $this->assets->url('quire-privacy/preview.css')];

        return [
            'banner' => $this->previews->document('<div class="consent-preview">'.str_replace(' data-consent-banner aria-labelledby="quire-consent-heading" hidden>', ' data-consent-banner aria-labelledby="quire-consent-heading">', $html).'</div>', $locale, $stylesheets),
            'dialog' => $this->previews->document(str_replace('<dialog class="consent-dialog" data-consent-dialog', '<dialog open class="consent-dialog" data-consent-dialog', $html), $locale, $stylesheets),
        ];
    }
}
