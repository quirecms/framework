<?php

declare(strict_types=1);

namespace Quire\Admin\Content;

use Quire\Content\Query\ContentQuery;

/**
 * A filter of the admin's content lists, added with {@see \Quire\Admin\Extend\Admin::contentFilter()}:
 * a choice of values that narrows the list.
 *
 * @api
 */
interface ContentFilterInterface
{
    /**
     * The filter's label, translated.
     */
    public function label(): string;

    /**
     * @return array<string, string> The values to choose from, with their translated labels
     */
    public function choices(): array;

    /**
     * Narrows the list to the chosen value, one of {@see choices()}.
     */
    public function apply(ContentQuery $query, string $value): ContentQuery;
}
