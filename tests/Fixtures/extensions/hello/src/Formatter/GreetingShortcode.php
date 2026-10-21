<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Formatter;

use Quire\Formatter\FormatContext;
use Quire\Formatter\Shortcode;
use Quire\Formatter\ShortcodeInterface;

/**
 * `[[greeting name=Ada]]`. Counts its renders, to show that shortcodes aren't cached.
 */
final class GreetingShortcode implements ShortcodeInterface
{
    public int $renders = 0;

    public function render(Shortcode $shortcode, FormatContext $context): string
    {
        ++$this->renders;

        if ($shortcode->has('fail')) {
            throw new \RuntimeException('The greeting failed.');
        }

        // Unescaped on purpose: untrusted shortcode output is sanitised.
        return \sprintf('<strong>Hello, %s!</strong>%s', $shortcode->attribute('name', 'world'), $shortcode->has('evil') ? '<script>alert(1)</script>' : '');
    }
}
