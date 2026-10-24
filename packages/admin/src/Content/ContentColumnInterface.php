<?php

declare(strict_types=1);

namespace Quire\Admin\Content;

use Quire\Content\Content;
use Quire\Content\Query\ContentQuery;

/**
 * A column of the admin's content lists, added with {@see \Quire\Admin\Extend\Admin::contentColumn()}.
 *
 *     final readonly class RatingColumn implements ContentColumnInterface
 *     {
 *         public function label(): string { return 'Rating'; }
 *         public function query(ContentQuery $query): ContentQuery { return $query; }
 *         public function value(Content $item): string { return str_repeat('★', (int) ($item->fields['rating'] ?? 0)); }
 *         public function sortBy(): ?string { return 'fields.rating'; }
 *     }
 *
 * @api
 */
interface ContentColumnInterface
{
    /**
     * The column's heading, translated.
     */
    public function label(): string;

    /**
     * Prepares the list's query for what the column shows, e.g. with select() or with(), so that it
     * doesn't need a query per row. Return it unchanged when the items have what it needs.
     */
    public function query(ContentQuery $query): ContentQuery;

    /**
     * The cell of an item. Strings are escaped; return a \Twig\Markup for HTML, escaping what's in it.
     */
    public function value(Content $item): string|\Stringable;

    /**
     * What the list sorts by when the column's heading is chosen, as {@see ContentQuery::orderBy()} takes
     * it, e.g. "fields.rating" or a value the column selects; null when the column can't sort.
     */
    public function sortBy(): ?string;
}
