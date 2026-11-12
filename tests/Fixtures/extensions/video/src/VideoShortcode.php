<?php

declare(strict_types=1);

namespace Quire\Fixtures\Video;

use Quire\Formatter\FormatContext;
use Quire\Formatter\Shortcode;
use Quire\Formatter\ShortcodeInterface;

/**
 * `[[fixture_video id=abc]]`, a trusted shortcode that embeds the fixture's video player: what
 * quire/privacy holds until the visitor allows the service.
 */
final class VideoShortcode implements ShortcodeInterface
{
    public function render(Shortcode $shortcode, FormatContext $context): string
    {
        return \sprintf('<iframe src="https://player.video.example/embed/%s" title="A fixture video" width="560" height="315"></iframe>', rawurlencode($shortcode->attribute('id') ?? ''));
    }
}
