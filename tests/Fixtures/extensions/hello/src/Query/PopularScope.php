<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Query;

use Quire\Content\Query\ContentQuery;

/**
 * Selects a value from the extension's own table and sorts by it.
 */
final readonly class PopularScope
{
    public function __invoke(ContentQuery $query, int $minimum = 0): ContentQuery
    {
        return $query
            ->select('like_count', 'SELECT count(*) FROM quire_fixture_hello_like l WHERE l.content_id = c.id')
            ->whereSql('(SELECT count(*) FROM quire_fixture_hello_like l WHERE l.content_id = c.id) >= ?', [$minimum])
            ->orderBy('like_count', 'desc');
    }
}
