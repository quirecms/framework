<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Query;

use Quire\Content\Query\ContentQuery;

final readonly class TaggedScope
{
    public function __invoke(ContentQuery $query, string $tag): ContentQuery
    {
        return $query->where('fields.tags', 'contains', $tag);
    }
}
