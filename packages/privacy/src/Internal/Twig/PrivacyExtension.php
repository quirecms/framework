<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal\Twig;

use Quire\Privacy\Internal\Theme\Banner;
use Twig\Attribute\AsTwigFunction;
use Twig\Environment;

/**
 * quire_consent_settings(): the "Cookie settings" opener where the theme wants it.
 *
 * @internal
 */
final readonly class PrivacyExtension
{
    public function __construct(private Banner $banner)
    {
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
