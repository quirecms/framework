<?php

declare(strict_types=1);

namespace Quire\Admin\Tests\Fixtures;

use Quire\Admin\Content\ContentColumnInterface;
use Quire\Content\Content;
use Quire\Content\Query\ContentQuery;

final class TitleLengthColumn implements ContentColumnInterface
{
    public function label(): string
    {
        return 'Length';
    }

    public function query(ContentQuery $query): ContentQuery
    {
        return $query->select('title_length', 'length(c.title)');
    }

    public function value(Content $item): string
    {
        $length = $item->attribute('title_length');

        return \is_int($length) ? (string) $length : '';
    }

    public function sortBy(): string
    {
        return 'title_length';
    }
}
