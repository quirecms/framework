<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Admin;

use Quire\Admin\Content\ContentFilterInterface;
use Quire\Content\Query\ContentQuery;

final class FeaturedFilter implements ContentFilterInterface
{
    public function label(): string
    {
        return 'Featured';
    }

    public function choices(): array
    {
        return ['yes' => 'Featured', 'no' => 'Not featured'];
    }

    public function apply(ContentQuery $query, string $value): ContentQuery
    {
        return $query->where('fields.featured', 'yes' === $value);
    }
}
