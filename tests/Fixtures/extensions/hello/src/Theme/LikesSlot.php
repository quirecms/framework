<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Theme;

use Quire\Content\Content;
use Quire\Theme\SlotRendererInterface;

/**
 * A slot fragment built in PHP: it goes before the template fragment, having a higher priority.
 */
final class LikesSlot implements SlotRendererInterface
{
    public function render(array $context): string
    {
        $content = $context['content'] ?? null;

        return $content instanceof Content ? \sprintf('<p class="fixture-likes">Like "%s"</p>', htmlspecialchars($content->title)) : '';
    }
}
