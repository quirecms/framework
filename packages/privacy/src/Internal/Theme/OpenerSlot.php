<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal\Theme;

use Quire\Theme\SlotRendererInterface;
use Twig\Environment;

/**
 * The "Cookie settings" opener in the footer, for themes that don't place it themselves with
 * quire_consent_settings(). Withdrawing must stay as easy as giving, on every page.
 *
 * @internal
 */
final readonly class OpenerSlot implements SlotRendererInterface
{
    public function __construct(private Banner $banner, private Environment $twig)
    {
    }

    public function render(array $context): string
    {
        return $this->banner->takeOpener() ? $this->twig->render('@quire_privacy/opener.html.twig') : '';
    }
}
