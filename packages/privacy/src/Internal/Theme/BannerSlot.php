<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal\Theme;

use Quire\Theme\SlotRendererInterface;
use Twig\Environment;

/**
 * The banner and the settings dialog, first in the body (after the theme's skip link), with the data
 * the consent loader reads and the loader itself. Nothing without a service to ask about.
 *
 * @internal
 */
final readonly class BannerSlot implements SlotRendererInterface
{
    public function __construct(private Banner $banner, private Environment $twig)
    {
    }

    public function render(array $context): string
    {
        if (!$this->banner->shown()) {
            return '';
        }

        return $this->twig->render('@quire_privacy/consent.html.twig', [
            ...$this->banner->view(),
            'config' => $this->banner->config(),
            'loader' => $this->banner->loaderUrl(),
        ]);
    }
}
