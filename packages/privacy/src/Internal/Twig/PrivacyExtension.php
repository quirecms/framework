<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal\Twig;

use Quire\Privacy\Internal\Theme\Banner;
use Quire\Privacy\Internal\Theme\ServicesList;
use Twig\Attribute\AsTwigFunction;
use Twig\Environment;

/**
 * quire_consent_settings(): the "Cookie settings" opener where the theme wants it; and
 * quire_privacy_services(): the site's services for its privacy notice.
 *
 * @internal
 */
final readonly class PrivacyExtension
{
    public function __construct(private Banner $banner, private ServicesList $services)
    {
    }

    /**
     * The site's services, for its privacy notice: who runs each, what for, what it keeps and where
     * data goes, from their declarations.
     */
    #[AsTwigFunction('quire_privacy_services', isSafe: ['html'])]
    public function services(): string
    {
        return $this->services->render();
    }

    /**
     * The opener, once per page; nothing on a site that asks visitors nothing. Hidden until the consent
     * loader runs, so it's never a dead button.
     */
    #[AsTwigFunction('quire_consent_settings', needsEnvironment: true, isSafe: ['html'])]
    public function settings(Environment $twig): string
    {
        return $this->banner->takeOpener() ? $twig->render('@quire_privacy/opener.html.twig') : '';
    }
}
