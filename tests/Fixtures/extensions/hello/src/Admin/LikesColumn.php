<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Admin;

use Quire\Admin\Content\ContentColumnInterface;
use Quire\Content\Content;
use Quire\Content\Query\ContentQuery;

/**
 * Who liked each post, loaded for the whole page at once; names are escaped.
 */
final class LikesColumn implements ContentColumnInterface
{
    public function label(): string
    {
        return 'Liked by';
    }

    public function query(ContentQuery $query): ContentQuery
    {
        return $query->with('likes');
    }

    public function value(Content $item): string
    {
        $likes = $item->attribute('likes');

        return \is_array($likes) && [] !== $likes ? implode(', ', array_filter($likes, \is_string(...))) : '—';
    }

    public function sortBy(): ?string
    {
        return null;
    }
}
