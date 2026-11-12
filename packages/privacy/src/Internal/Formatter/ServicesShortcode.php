<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal\Formatter;

use Quire\Formatter\FormatContext;
use Quire\Formatter\Shortcode;
use Quire\Formatter\ShortcodeInterface;
use Quire\Privacy\Internal\Theme\ServicesList;

/**
 * [[privacy_services]] in a page's text: the site's services for its privacy notice, from their
 * declarations, as they are when the page is shown.
 *
 * @internal
 */
final readonly class ServicesShortcode implements ShortcodeInterface
{
    public function __construct(private ServicesList $list)
    {
    }

    public function render(Shortcode $shortcode, FormatContext $context): string
    {
        return $this->list->render();
    }
}
