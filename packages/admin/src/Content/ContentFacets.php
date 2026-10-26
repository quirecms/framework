<?php

declare(strict_types=1);

namespace Quire\Admin\Content;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Quire\Content\Check\ContentChecker;
use Quire\Content\Field\FieldStorage;
use Quire\Content\Type\ContentTypeDefinition;
use Quire\Content\Workflow\ContentWorkflow;
use Quire\Settings\Settings;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The facets of a content list, with how many items each value would find: the numbers next to its
 * filters. Each facet is counted with the list's other filters but not its own, so people see what else
 * they could choose (one query per facet, see {@see \Quire\Content\Query\ContentQuery::countBy()}).
 *
 * @internal
 *
 * @phpstan-type Option array{value: string, label: string, count: int, stage: ?string}
 * @phpstan-type Group array{id: string, label: string, options: list<Option>, more: int}
 */
final readonly class ContentFacets
{
    /** Values shown before "all …" */
    public const int SHOWN = 5;

    public function __construct(
        private ContentLists $lists,
        private ContentWorkflow $workflow,
        private ContentChecker $checker,
        private Connection $connection,
        private Settings $settings,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<Group> Facets that have something to choose, in order
     */
    public function groups(ContentTypeDefinition $type, ListState $state): array
    {
        $groups = [];

        foreach ($this->lists->facets($type) as $facet) {
            $base = $this->lists->filtered($type, $state, except: $facet);
            $group = match (true) {
                'status' === $facet => $this->statuses($type, $base),
                'checks' === $facet => $this->checks($base),
                'locale' === $facet => $this->locales($base->countBy('locale')),
                default => $this->field($type, $facet, $base->countBy($facet)),
            };

            // A facet that can't tell items apart is noise, unless something of it is chosen.
            $useful = \count(array_filter($group['options'], static fn (array $option): bool => $option['count'] > 0)) > ('locale' === $facet ? 1 : 0);

            if ($useful || [] !== ($state->facets[$facet] ?? [])) {
                $groups[] = ['id' => $facet, ...$group, 'options' => str_starts_with($facet, 'fields.') ? $this->chosenFirst($group['options'], $state->facets[$facet] ?? []) : $group['options']];
            }
        }

        return array_map(static fn (array $group): array => [...$group, 'more' => max(0, \count($group['options']) - self::SHOWN)], $groups);
    }

    /**
     * Words of the search syntax for facets, e.g. "tag" for "fields.tags" and "check" for "checks".
     *
     * @return array<string, string>
     */
    public function aliases(ContentTypeDefinition $type): array
    {
        $aliases = ['check' => 'checks', 'lang' => 'locale'];

        foreach ($this->lists->facets($type) as $facet) {
            if (str_starts_with($facet, 'fields.') && str_ends_with($facet, 's')) {
                $aliases[substr($facet, \strlen('fields.'), -1)] = $facet;
            }
        }

        return $aliases;
    }

    /**
     * @return array{label: string, options: list<Option>}
     */
    private function statuses(ContentTypeDefinition $type, \Quire\Content\Query\ContentQuery $base): array
    {
        $counts = $base->countBy('status');
        $review = $this->lists->narrow($type, $base, 'status', ['review'])->count();
        $options = [];

        foreach (array_keys($this->workflow->statuses()) as $status) {
            $options[] = ['value' => $status, 'label' => $this->lists->statusLabel($status), 'count' => $counts[$status] ?? 0, 'stage' => ContentLists::stage($status)];

            // Changes in review sit between drafts and what's scheduled, as in the lifecycle.
            if ('draft' === $status) {
                $options[] = ['value' => 'review', 'label' => $this->translator->trans('In review', [], 'quire-admin'), 'count' => $review, 'stage' => 'review'];
            }
        }

        return ['label' => $this->translator->trans('Where it is', [], 'quire-admin'), 'options' => array_values(array_filter($options, static fn (array $option): bool => $option['count'] > 0 || 'archived' !== $option['value']))];
    }

    /**
     * @return array{label: string, options: list<Option>}
     */
    private function checks(\Quire\Content\Query\ContentQuery $base): array
    {
        $counts = $base->countBy('checks');
        $options = [['value' => 'any', 'label' => $this->translator->trans('any', [], 'quire-admin'), 'count' => $base->withIssues()->count(), 'stage' => null]];

        foreach ($this->checker->checks() as $check) {
            if (null !== $check['level'] && ($counts[$check['id']] ?? 0) > 0) {
                $options[] = ['value' => $check['id'], 'label' => $this->checkLabel($check['id'], $check['label']), 'count' => $counts[$check['id']], 'stage' => null];
            }
        }

        return ['label' => $this->translator->trans('Something to check', [], 'quire-admin'), 'options' => $options];
    }

    /**
     * @param array<int|string, int> $counts
     *
     * @return array{label: string, options: list<Option>}
     */
    private function locales(array $counts): array
    {
        $display = $this->settings->getString('site.locale');
        $options = [];

        foreach ($counts as $locale => $count) {
            $name = \Locale::getDisplayName((string) $locale, $display);
            $options[] = ['value' => (string) $locale, 'label' => \is_string($name) ? mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1) : (string) $locale, 'count' => $count, 'stage' => null];
        }

        return ['label' => $this->translator->trans('Language', [], 'quire-admin'), 'options' => $options];
    }

    /**
     * @param array<int|string, int> $counts
     *
     * @return array{label: string, options: list<Option>}
     */
    private function field(ContentTypeDefinition $type, string $facet, array $counts): array
    {
        $field = $type->field(substr($facet, \strlen('fields.')));
        \assert($field instanceof \Quire\Content\Field\FieldDefinition);
        $labels = match ($field->storage()) {
            FieldStorage::Boolean => ['true' => $this->translator->trans('Yes', [], 'quire-admin'), 'false' => $this->translator->trans('No', [], 'quire-admin')],
            FieldStorage::Relation => $this->titles(array_map(strval(...), array_keys($counts))),
            default => [],
        };
        $options = [];

        foreach ($counts as $value => $count) {
            $options[] = ['value' => (string) $value, 'label' => $labels[(string) $value] ?? (string) $value, 'count' => $count, 'stage' => null];
        }

        return ['label' => $field->label ?? ucfirst(str_replace('_', ' ', $field->name)), 'options' => $options];
    }

    /**
     * A finding's short name, e.g. "Broken link" for the links check, as lists show it.
     */
    public function checkLabel(string $check, string $fallback): string
    {
        $key = 'check.'.$check;
        $label = $this->translator->trans($key, [], 'quire-admin');

        return $label === $key ? $fallback : $label;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, string> Titles of linked items, by ID
     */
    private function titles(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $titles = [];

        foreach ($this->connection->fetchAllNumeric('SELECT id::text, title FROM quire.content WHERE id::text IN (?)', [$ids], [ArrayParameterType::STRING]) as [$id, $title]) {
            if (\is_string($id) && \is_string($title)) {
                $titles[$id] = $title;
            }
        }

        return $titles;
    }

    /**
     * @param list<Option> $options
     * @param list<string> $chosen
     *
     * @return list<Option> What's chosen first, so it's in the values shown, then by count
     */
    private function chosenFirst(array $options, array $chosen): array
    {
        usort($options, static fn (array $a, array $b): int => (\in_array($b['value'], $chosen, true) <=> \in_array($a['value'], $chosen, true)));

        return $options;
    }
}
