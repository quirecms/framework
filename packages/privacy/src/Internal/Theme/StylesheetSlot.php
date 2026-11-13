<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal\Theme;

use Quire\Theme\SlotRendererInterface;

/**
 * The banner's styles, in the head: only on sites that show it.
 *
 * @internal
 */
final readonly class StylesheetSlot implements SlotRendererInterface
{
    public function __construct(private Banner $banner)
    {
    }

    public function render(array $context): string
    {
        return $this->banner->onThisPage() ? \sprintf('<link rel="stylesheet" href="%s">', htmlspecialchars($this->banner->stylesheetUrl())) : '';
    }
}
