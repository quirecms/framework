<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Formatter;

use Quire\Formatter\FormatContext;
use Quire\Formatter\Shortcode;
use Quire\Formatter\ShortcodeInterface;

/**
 * `[[embed id=abc]]`, a trusted shortcode: its iframe survives.
 */
final class EmbedShortcode implements ShortcodeInterface
{
    public function render(Shortcode $shortcode, FormatContext $context): string
    {
        return \sprintf('<iframe src="https://video.example/%s"></iframe>', rawurlencode($shortcode->attribute('id') ?? ''));
    }
}
