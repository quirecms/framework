<?php

declare(strict_types=1);

namespace App\Ci;

use Quire\Formatter\FormatContext;
use Quire\Formatter\Shortcode;
use Quire\Formatter\ShortcodeInterface;

/**
 * CI only: `[[ci_event]]`, buttons marked with events for quire/analytics, as a theme would mark them:
 * one with a long value and more parameters than GA4 takes, and one whose name isn't valid.
 */
final readonly class CiEventShortcode implements ShortcodeInterface
{
    public function render(Shortcode $shortcode, FormatContext $context): string
    {
        $parameters = '';

        for ($i = 1; $i <= 30; ++$i) {
            $parameters .= \sprintf(' data-quire-event-p%d="%d"', $i, $i);
        }

        return \sprintf('<p><button type="button" data-ci-event data-quire-event="ci_click" data-quire-event-link-text="%s"%s>Count me</button> <button type="button" data-ci-bad-event data-quire-event="1bad">Not an event</button></p>', str_repeat('x', 150), $parameters);
    }
}
