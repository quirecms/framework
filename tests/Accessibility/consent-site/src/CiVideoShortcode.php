<?php

declare(strict_types=1);

namespace App\Ci;

use Quire\Formatter\FormatContext;
use Quire\Formatter\Shortcode;
use Quire\Formatter\ShortcodeInterface;
use Symfony\Component\Asset\Packages;

/**
 * CI only (copied to src/Ci/ while consent.mjs runs): `[[ci_video]]`, a trusted shortcode embedding
 * "CI Video" from the site's own host, which the service declares, so the browser can load it: a
 * script that counts its runs, an inline script that the policy must block, and a frame.
 */
final readonly class CiVideoShortcode implements ShortcodeInterface
{
    public function __construct(private Packages $packages)
    {
    }

    public function render(Shortcode $shortcode, FormatContext $context): string
    {
        return \sprintf(
            '<div class="ci-video"><script src="https://quire.localhost%s"></script><script>document.documentElement.dataset.ciInline = "ran";</script><iframe src="https://quire.localhost/about" title="CI Video player" data-ci-frame></iframe></div>',
            htmlspecialchars($this->packages->getUrl('ci-consent/embed.js')),
        );
    }
}
