<?php

declare(strict_types=1);

namespace App\Ci;

use Quire\Formatter\FormatContext;
use Quire\Formatter\Shortcode;
use Quire\Formatter\ShortcodeInterface;

/**
 * CI only: `[[ci_probe]]`, the architect's probe of C2c (review R1). A frame of "CI Video", and a value
 * escaped as it should be, in an attribute inside <noscript>: written back by a parser with scripting
 * off, it would end the placeholder in a browser and load the image before consent.
 */
final readonly class CiProbeShortcode implements ShortcodeInterface
{
    public function render(Shortcode $shortcode, FormatContext $context): string
    {
        return '<iframe src="https://quire.localhost/about" title="Probe"></iframe><noscript><p title="&lt;/noscript&gt;&lt;/template&gt;&lt;img src=https://quire.localhost/ci-leak.png&gt;">No JavaScript.</p></noscript>';
    }
}
