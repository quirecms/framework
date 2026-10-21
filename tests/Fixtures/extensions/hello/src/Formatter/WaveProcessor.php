<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Formatter;

use Quire\Formatter\FormatContext;
use Quire\Formatter\SourceProcessorInterface;

final class WaveProcessor implements SourceProcessorInterface
{
    public function process(string $source, FormatContext $context): string
    {
        return str_replace(':wave:', '👋', $source);
    }
}
