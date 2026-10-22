<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Query;

use Quire\Content\Query\ContentQuery;

final readonly class FeaturedScope
{
    public function __invoke(ContentQuery $query): ContentQuery
    {
        return $query->where('fields.featured', true);
    }
}
