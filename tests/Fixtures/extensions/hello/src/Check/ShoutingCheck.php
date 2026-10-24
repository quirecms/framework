<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Check;

use Quire\Content\Check\ContentCheckInterface;
use Quire\Content\Check\Finding;
use Quire\Content\Content;

/**
 * Titles aren't all capitals.
 */
final class ShoutingCheck implements ContentCheckInterface
{
    public function check(Content $item): iterable
    {
        if (1 === preg_match('/\p{L}/u', $item->title) && mb_strtoupper($item->title) === $item->title) {
            yield new Finding('The title is in capitals, which reads as shouting.', 'title');
        }
    }
}
