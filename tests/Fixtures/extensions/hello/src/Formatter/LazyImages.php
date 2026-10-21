<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Formatter;

use Quire\Formatter\FormatContext;
use Quire\Formatter\HtmlProcessorInterface;

final class LazyImages implements HtmlProcessorInterface
{
    public function process(string $html, FormatContext $context): string
    {
        return str_replace('<img ', '<img loading="lazy" ', $html);
    }
}
