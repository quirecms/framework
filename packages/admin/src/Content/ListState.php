<?php

declare(strict_types=1);

namespace Quire\Admin\Content;

use Symfony\Component\HttpFoundation\Request;

/**
 * What a content list shows, as its URL says: so a list can be bookmarked, shared, and works without
 * JavaScript. Unknown values are left out.
 *
 *     ?view=mine&f[status][]=draft&f[fields.tags][]=draw&q=draw&sort=-updated_at&mode=list
 *
 * Search words like "tag:draw" or "status:draft" become filters (what "Under the hood" shows): see
 * {@see parse()}.
 *
 * @internal
 */
final readonly class ListState
{
    public const array VIEWS = ['needs-me', 'mine', 'all', ContentLists::TRASH];

    /** As the switch shows them: what's moving, everything, and when */
    public const array MODES = ['board', 'list', 'calendar'];

    /**
     * @param array<string, list<string>> $facets  Chosen values, by facet: "status", "checks", "locale", "fields.<name>"
     * @param array<string, string>       $filters Chosen values of extensions' filters, by ID
     * @param string|null                 $month   The calendar's month, e.g. "2026-10"
     */
    public function __construct(
        public string $view = 'all',
        public string $q = '',
        public string $sort = '',
        public int $page = 1,
        public array $facets = [],
        public array $filters = [],
        public string $mode = 'list',
        public ?string $month = null,
    ) {
    }

    /**
     * @param list<string>          $facets  The facets the list has
     * @param list<string>          $filters The extensions' filters it has
     * @param array<string, string> $aliases Words of the search syntax, by facet, e.g. ["tag" => "fields.tags"]
     */
    public static function fromRequest(Request $request, array $facets, array $filters, array $aliases = []): self
    {
        $query = $request->query;
        $chosen = [];

        foreach ($query->all('f') as $facet => $values) {
            if (\in_array($facet, $facets, true)) {
                $chosen[$facet] = array_values(array_unique(array_filter(\is_array($values) ? $values : [$values], static fn (mixed $value): bool => \is_string($value) && '' !== $value)));
            }
        }

        [$q, $searched] = self::parse(mb_substr(trim($query->getString('q')), 0, 200), $facets, $aliases);

        foreach ($searched as $facet => $values) {
            $chosen[$facet] = array_values(array_unique([...$chosen[$facet] ?? [], ...$values]));
        }

        $month = $query->getString('month');
        $view = $query->getString('view', 'all');
        $mode = $query->getString('mode', 'list');

        return new self(
            view: \in_array($view, self::VIEWS, true) ? $view : 'all',
            q: $q,
            sort: $query->getString('sort'),
            page: max(1, $query->getInt('page', 1)),
            facets: array_filter($chosen, static fn (array $values): bool => [] !== $values),
            filters: array_filter(array_intersect_key($query->all('filter'), array_flip($filters)), static fn (mixed $value): bool => \is_string($value) && '' !== $value),
            mode: \in_array($mode, self::MODES, true) ? $mode : 'list',
            month: 1 === preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? $month : null,
        );
    }

    /**
     * Takes the words that choose a facet's value, e.g. "tag:draw", out of a search.
     *
     * @param list<string>          $facets
     * @param array<string, string> $aliases
     *
     * @return array{0: string, 1: array<string, list<string>>} The rest of the search, and the values by facet
     */
    public static function parse(string $search, array $facets, array $aliases = []): array
    {
        $chosen = [];
        $rest = preg_replace_callback('/(?<=^|\s)([a-z][a-z0-9_.-]*):("[^"]+"|\S+)/i', static function (array $match) use ($facets, $aliases, &$chosen): string {
            $word = strtolower($match[1]);
            $facet = $aliases[$word] ?? (\in_array($word, $facets, true) ? $word : (\in_array('fields.'.$word, $facets, true) ? 'fields.'.$word : null));

            if (null === $facet) {
                return $match[0];
            }

            $chosen[$facet][] = trim($match[2], '"');

            return '';
        }, $search) ?? $search;

        return [trim((string) preg_replace('/\s+/', ' ', $rest)), $chosen];
    }

    /**
     * The search words for the chosen facets, e.g. "tag:draw status:draft".
     *
     * @param array<string, string> $aliases
     */
    public function syntax(array $aliases = []): string
    {
        $words = array_flip($aliases);
        $parts = [];

        foreach ($this->facets as $facet => $values) {
            $word = $words[$facet] ?? (str_starts_with($facet, 'fields.') ? substr($facet, \strlen('fields.')) : $facet);

            foreach ($values as $value) {
                $parts[] = $word.':'.(str_contains($value, ' ') ? '"'.$value.'"' : $value);
            }
        }

        return implode(' ', $parts);
    }

    public function has(string $facet, string $value): bool
    {
        return \in_array($value, $this->facets[$facet] ?? [], true);
    }

    /**
     * The state with a facet's value chosen or not, back on the first page.
     */
    public function toggled(string $facet, string $value): self
    {
        $values = $this->facets[$facet] ?? [];
        $values = $this->has($facet, $value) ? array_values(array_diff($values, [$value])) : [...$values, $value];
        $facets = [...$this->facets, $facet => $values];

        return $this->with(page: 1, facets: array_filter($facets, static fn (array $chosen): bool => [] !== $chosen));
    }

    /**
     * @param array<string, list<string>>|null $facets
     * @param array<string, string>|null       $filters
     */
    public function with(?string $view = null, ?string $q = null, ?string $sort = null, ?int $page = null, ?array $facets = null, ?array $filters = null, ?string $mode = null, ?string $month = null): self
    {
        return new self($view ?? $this->view, $q ?? $this->q, $sort ?? $this->sort, $page ?? $this->page, $facets ?? $this->facets, $filters ?? $this->filters, $mode ?? $this->mode, $month ?? $this->month);
    }

    /**
     * The state with the calendar on the current month.
     */
    public function thisMonth(): self
    {
        return new self($this->view, $this->q, $this->sort, $this->page, $this->facets, $this->filters, $this->mode);
    }

    public function isFiltered(): bool
    {
        return '' !== $this->q || [] !== $this->facets || [] !== $this->filters;
    }

    /**
     * The state as query parameters, without defaults.
     *
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        return array_filter([
            'view' => 'all' === $this->view ? null : $this->view,
            'mode' => 'list' === $this->mode ? null : $this->mode,
            'q' => '' === $this->q ? null : $this->q,
            'f' => [] === $this->facets ? null : $this->facets,
            'filter' => [] === $this->filters ? null : $this->filters,
            'sort' => '' === $this->sort ? null : $this->sort,
            'page' => 1 === $this->page ? null : $this->page,
            'month' => $this->month,
        ], static fn (mixed $value): bool => null !== $value);
    }
}
