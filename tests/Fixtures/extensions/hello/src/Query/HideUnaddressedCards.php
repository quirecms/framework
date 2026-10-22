<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Query;

use Quire\Content\Query\ContentQuery;

/**
 * A visibility rule: cards addressed to "nobody" aren't shown to visitors.
 */
final readonly class HideUnaddressedCards
{
    public function __invoke(ContentQuery $query): ContentQuery
    {
        return $query->where('fields.recipient', '!=', 'nobody');
    }
}
