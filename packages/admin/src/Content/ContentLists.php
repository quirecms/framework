<?php

declare(strict_types=1);

namespace Quire\Admin\Content;

use Psr\Log\LoggerInterface;
use Quire\Admin\Extend\Admin;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Query\ContentPage;
use Quire\Content\Query\ContentQuery;
use Quire\Content\Status;
use Quire\Content\Type\ContentTypeDefinition;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Content\Workflow\ContentWorkflow;
use Quire\User\CurrentUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Markup;

/**
 * The admin's list of each content type: what the current user may see of it, with its views, search,
 * filters, columns, sorting and pages, and the actions they may take on its items.
 *
 * Editors with "content.<type>.edit_any" see every item of the type, those with only "edit_own" their
 * own. Changing the status takes "publish" for core's transitions (an extension's take the right to edit
 * the item), and the trash takes "delete".
 *
 * @internal
 *
 * @phpstan-type Entry array{id: string, kind: string, class: string, types: ?list<string>, order: int, owner: string}
 * @phpstan-type Column array{id: string, label: string, sort: ?string, value: \Closure(Content): (string|\Stringable)}
 */
final readonly class ContentLists
{
    public const int PER_PAGE = 20;

    /** The view of items in the trash, next to one per status */
    public const string TRASH = 'trash';

    /** Core's transitions that change what visitors see, which take "publish" */
    private const array PUBLISHING = [Status::PUBLISH, Status::UNPUBLISH, Status::ARCHIVE];

    /** Transitions that need more than a click, e.g. a date: they're for the editor, not the list */
    private const array NOT_IN_LISTS = [Status::SCHEDULE];

    /**
     * @param list<Entry>                                                                          $entries
     * @param ServiceLocator<ContentColumnInterface|ContentFilterInterface|ContentActionInterface> $services
     */
    public function __construct(
        private ContentTypeRegistry $types,
        private ContentRepository $content,
        private ContentWriter $writer,
        private ContentWorkflow $workflow,
        private Security $security,
        private CurrentUser $users,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        #[Autowire(param: Admin::CONTENT_LIST_PARAMETER)]
        private array $entries,
        #[AutowireLocator(Admin::CONTENT_LIST_TAG)]
        private ServiceLocator $services,
    ) {
    }

    /**
     * The types whose list the current user may see, in the registry's order.
     *
     * @return list<ContentTypeDefinition>
     */
    public function listableTypes(): array
    {
        return array_values(array_filter($this->types->all(), $this->canList(...)));
    }

    public function canList(ContentTypeDefinition $type): bool
    {
        return $this->security->isGranted(self::ability($type, 'edit_any')) || $this->security->isGranted(self::ability($type, 'edit_own'));
    }

    /**
     * Everything of the type the current user may see in the list: drafts and archived items included,
     * items in the trash only with {@see withTrashed}.
     */
    public function query(ContentTypeDefinition $type, bool $withTrashed = false): ContentQuery
    {
        $query = $this->content->query($type->id)->withoutVisibilityScope();

        if ($withTrashed) {
            $query = $query->withTrashed();
        }

        if (!$this->security->isGranted(self::ability($type, 'edit_any'))) {
            // Their own items only; without a Quire user to be the author of any, none.
            return $query->where('author_id', $this->users->get()->id ?? '00000000-0000-0000-0000-000000000000');
        }

        return $query;
    }

    /**
     * The views of the list: all items not in the trash, one per status, and the trash, with how many
     * items each has.
     *
     * @return list<array{id: string, label: string, count: int}>
     */
    public function views(ContentTypeDefinition $type): array
    {
        $query = $this->query($type);
        $views = [['id' => 'all', 'label' => $this->translator->trans('All', [], 'quire-admin'), 'count' => $query->count()]];

        foreach (array_keys($this->workflow->statuses()) as $status) {
            $views[] = ['id' => $status, 'label' => $this->statusLabel($status), 'count' => $query->status($status)->count()];
        }

        $views[] = ['id' => self::TRASH, 'label' => $this->translator->trans('Trash', [], 'quire-admin'), 'count' => $query->onlyTrashed()->count()];

        return $views;
    }

    public function statusLabel(string $status): string
    {
        $key = 'status.'.$status;
        $label = $this->translator->trans($key, [], 'quire-admin');

        return $label === $key ? ucfirst(str_replace('_', ' ', $status)) : $label;
    }

    /**
     * The columns, in order: the title, the status, the author when the type has one, the sortable fields,
     * the dates, then the extensions'.
     *
     * @return list<Column>
     */
    public function columns(ContentTypeDefinition $type): array
    {
        $columns = [
            ['id' => 'title', 'label' => $this->translator->trans('Title', [], 'quire-admin'), 'sort' => 'title', 'value' => static fn (Content $item): string => $item->title],
            ['id' => 'status', 'label' => $this->translator->trans('Status', [], 'quire-admin'), 'sort' => null, 'value' => $this->statusCell(...)],
        ];

        if ($type->has('author')) {
            $columns[] = ['id' => 'author', 'label' => $this->translator->trans('Author', [], 'quire-admin'), 'sort' => null, 'value' => static fn (Content $item): string => \is_string($name = $item->attributes['author_name'] ?? null) ? $name : '—'];
        }

        foreach ($type->fields as $field) {
            if ($field->sortable) {
                $columns[] = ['id' => 'fields.'.$field->name, 'label' => $field->label ?? ucfirst(str_replace('_', ' ', $field->name)), 'sort' => 'fields.'.$field->name, 'value' => static fn (Content $item): string => self::fieldValue($item->fields[$field->name] ?? null)];
            }
        }

        $columns[] = ['id' => 'updated_at', 'label' => $this->translator->trans('Updated', [], 'quire-admin'), 'sort' => 'updated_at', 'value' => $this->dateCell(...)];
        $columns[] = ['id' => 'published_at', 'label' => $this->translator->trans('Published', [], 'quire-admin'), 'sort' => 'published_at', 'value' => static fn (Content $item): string|Markup => $item->publishedAt instanceof \DateTimeImmutable ? self::time($item->publishedAt) : '—'];

        foreach ($this->extensions($type, 'column') as $id => $column) {
            \assert($column instanceof ContentColumnInterface);
            $columns[] = ['id' => $id, 'label' => $column->label(), 'sort' => $column->sortBy(), 'value' => $column->value(...)];
        }

        return $columns;
    }

    /**
     * @return array<string, ContentFilterInterface> By ID
     */
    public function filters(ContentTypeDefinition $type): array
    {
        $filters = [];

        foreach ($this->extensions($type, 'filter') as $id => $filter) {
            \assert($filter instanceof ContentFilterInterface);
            $filters[$id] = $filter;
        }

        return $filters;
    }

    /**
     * One page of the list.
     *
     * @param string                $view    "all", a status, or {@see TRASH}
     * @param string                $sort    A column's sort, e.g. "title", or "-updated_at" for descending; empty for the default
     * @param array<string, string> $filters Chosen values, by filter ID
     */
    public function page(ContentTypeDefinition $type, string $view, string $search, string $sort, array $filters, int $page): ContentPage
    {
        $query = $this->query($type)->search($search, prefix: true);
        $query = match ($view) {
            'all' => $query,
            self::TRASH => $query->onlyTrashed(),
            default => $query->status($view),
        };

        foreach ($this->filters($type) as $id => $filter) {
            if (isset($filters[$id]) && \array_key_exists($filters[$id], $filter->choices())) {
                $query = $filter->apply($query, $filters[$id]);
            }
        }

        if ($type->has('author')) {
            $query = $query->select('author_name', 'SELECT u.display_name FROM quire.app_user u WHERE u.id = c.author_id');
        }

        // Changes to published items that aren't published yet.
        $query = $query->select('pending_changes', 'SELECT count(*) FROM quire.content_pending_revision p WHERE p.content_id = c.id');

        foreach ($this->extensions($type, 'column') as $column) {
            \assert($column instanceof ContentColumnInterface);
            $query = $column->query($query);
        }

        [$field, $direction] = $this->sort($type, $sort, '' !== trim($search));

        if (null !== $field) {
            $query = $query->orderBy($field, $direction);
        }

        return $query->paginate($page, self::PER_PAGE);
    }

    /**
     * The sort the list uses: a sortable column, or the latest change first, or best matches first when it
     * searches.
     *
     * @return array{0: ?string, 1: 'asc'|'desc'} The field, null for relevance, and the direction
     */
    public function sort(ContentTypeDefinition $type, string $sort, bool $searching): array
    {
        $field = ltrim($sort, '-');
        $sortable = array_filter(array_column($this->columns($type), 'sort'), static fn (?string $sort): bool => null !== $sort);

        if ('' !== $field && \in_array($field, $sortable, true)) {
            return [$field, str_starts_with($sort, '-') ? 'desc' : 'asc'];
        }

        return $searching ? [null, 'desc'] : ['updated_at', 'desc'];
    }

    /**
     * The actions the current user may take on the item now, in order: changes of status, then the
     * extensions', then the trash.
     *
     * @return array<string, string> Labels, by action
     */
    public function actions(ContentTypeDefinition $type, Content $item): array
    {
        $actions = [];

        if ($item->deletedAt instanceof \DateTimeImmutable) {
            if ($this->security->isGranted(self::ability($type, 'delete'))) {
                $actions['restore'] = $this->translator->trans('Restore', [], 'quire-admin');
                $actions['purge'] = $this->translator->trans('Delete permanently', [], 'quire-admin');
            }

            return $actions;
        }

        foreach ($this->workflow->enabledTransitions($item) as $transition) {
            if (!\in_array($transition, self::NOT_IN_LISTS, true) && $this->mayTransition($type, $item, $transition)) {
                $actions['transition:'.$transition] = $this->transitionLabel($transition);
            }
        }

        foreach ($this->extensions($type, 'action') as $id => $action) {
            \assert($action instanceof ContentActionInterface);

            if ($action->isAvailable($item)) {
                $actions['extension:'.$id] = $action->label();
            }
        }

        if ($this->security->isGranted(self::ability($type, 'delete'))) {
            $actions['trash'] = $this->translator->trans('Move to trash', [], 'quire-admin');
        }

        return $actions;
    }

    /**
     * Every action the list's items might offer, for choosing one for the selected items.
     *
     * @return array<string, string> Labels, by action
     */
    public function bulkActions(ContentTypeDefinition $type, bool $trash): array
    {
        if ($trash) {
            return $this->security->isGranted(self::ability($type, 'delete'))
                ? ['restore' => $this->translator->trans('Restore', [], 'quire-admin'), 'purge' => $this->translator->trans('Delete permanently', [], 'quire-admin')]
                : [];
        }

        $actions = [];

        foreach ($this->workflowTransitions($type) as $transition) {
            $actions['transition:'.$transition] = $this->transitionLabel($transition);
        }

        foreach ($this->extensions($type, 'action') as $id => $action) {
            \assert($action instanceof ContentActionInterface);
            $actions['extension:'.$id] = $action->label();
        }

        if ($this->security->isGranted(self::ability($type, 'delete'))) {
            $actions['trash'] = $this->translator->trans('Move to trash', [], 'quire-admin');
        }

        return $actions;
    }

    /**
     * Takes the action on each of the items it's available for.
     *
     * @param list<string> $ids
     *
     * @return array{label: string, done: int, failed: list<array{title: string, reason: string}>}
     */
    public function apply(ContentTypeDefinition $type, string $action, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (string $id): bool => 1 === preg_match('/^[0-9a-f-]{36}$/', $id))));
        // Through the list's query: only items of this type that the user may see in it.
        $items = [] === $ids ? [] : $this->query($type, withTrashed: true)->where('id', 'in', $ids)->get();
        $label = $this->bulkActions($type, true)[$action] ?? $this->bulkActions($type, false)[$action] ?? $action;
        $done = 0;
        $failed = [];

        foreach ($items as $item) {
            if (!\array_key_exists($action, $this->actions($type, $item))) {
                $failed[] = ['title' => $item->title, 'reason' => $this->translator->trans('This isn\'t possible for it now.', [], 'quire-admin')];

                continue;
            }

            try {
                $this->take($type, $item, $action);
                ++$done;
            } catch (ContentActionException|\Quire\Content\Exception\ContentConflictException|\Quire\Content\Exception\TransitionNotAllowedException|\Quire\Content\Exception\InvalidContentException|\Quire\Content\Exception\ContentOperationCancelledException $e) {
                $failed[] = ['title' => $item->title, 'reason' => $e->getMessage()];
            } catch (\Throwable $e) {
                $this->logger->error('The content list action "{action}" failed for {id}: {message}', ['action' => $action, 'id' => $item->id, 'message' => $e->getMessage(), 'exception' => $e]);
                $failed[] = ['title' => $item->title, 'reason' => $this->translator->trans('Something went wrong; it\'s in the log.', [], 'quire-admin')];
            }
        }

        return ['label' => $label, 'done' => $done, 'failed' => $failed];
    }

    public static function ability(ContentTypeDefinition $type, string $action): string
    {
        return \sprintf('content.%s.%s', $type->id, $action);
    }

    private function take(ContentTypeDefinition $type, Content $item, string $action): void
    {
        match (true) {
            str_starts_with($action, 'transition:') => $this->writer->transition($item->id, $item->version, substr($action, \strlen('transition:'))),
            str_starts_with($action, 'extension:') => $this->extensionAction($type, substr($action, \strlen('extension:')))->apply($item),
            'trash' === $action => $this->writer->trash($item->id, $item->version),
            'restore' === $action => $this->writer->restore($item->id),
            'purge' === $action => $this->writer->purge($item->id),
            default => throw new \LogicException(\sprintf('Unknown action "%s".', $action)),
        };
    }

    private function mayTransition(ContentTypeDefinition $type, Content $item, string $transition): bool
    {
        if (\in_array($transition, self::PUBLISHING, true)) {
            return $this->security->isGranted(self::ability($type, 'publish'));
        }

        return $this->security->isGranted(self::ability($type, 'edit_any')) || $this->security->isGranted(self::ability($type, 'edit_own'), $item);
    }

    private function transitionLabel(string $transition): string
    {
        $key = 'transition.'.$transition;
        $label = $this->translator->trans($key, [], 'quire-admin');

        return $label === $key ? ucfirst(str_replace('_', ' ', $transition)) : $label;
    }

    /**
     * @return list<string> The names of the workflow's transitions that lists offer and the user may take
     *                      on some item of the type
     */
    private function workflowTransitions(ContentTypeDefinition $type): array
    {
        $publish = $this->security->isGranted(self::ability($type, 'publish'));
        $edit = $this->security->isGranted(self::ability($type, 'edit_any')) || $this->security->isGranted(self::ability($type, 'edit_own'));

        return array_values(array_filter(
            $this->workflow->transitions(),
            static fn (string $transition): bool => !\in_array($transition, self::NOT_IN_LISTS, true) && (\in_array($transition, self::PUBLISHING, true) ? $publish : $edit),
        ));
    }

    private function extensionAction(ContentTypeDefinition $type, string $id): ContentActionInterface
    {
        $action = $this->extensions($type, 'action')[$id] ?? throw new \LogicException(\sprintf('Unknown action "%s".', $id));
        \assert($action instanceof ContentActionInterface);

        return $action;
    }

    /**
     * @return array<string, object> The extensions' columns, filters or actions for the type, by ID, in order
     */
    private function extensions(ContentTypeDefinition $type, string $kind): array
    {
        $entries = array_values(array_filter($this->entries, static fn (array $entry): bool => $kind === $entry['kind'] && (null === $entry['types'] || \in_array($type->id, $entry['types'], true))));
        usort($entries, static fn (array $a, array $b): int => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);
        $services = [];

        foreach ($entries as $entry) {
            $services[$entry['id']] = $this->services->get($entry['class']);
        }

        return $services;
    }

    private function statusCell(Content $item): Markup
    {
        $label = $item->deletedAt instanceof \DateTimeImmutable ? $this->translator->trans('In the trash', [], 'quire-admin') : $this->statusLabel($item->status);

        $html = \sprintf('<span class="status status--%s">%s</span>', htmlspecialchars($item->status), htmlspecialchars($label));
        $pending = $item->attributes['pending_changes'] ?? 0;

        if (is_numeric($pending) && (int) $pending > 0) {
            $html .= \sprintf(' <span class="meta">%s</span>', htmlspecialchars($this->translator->trans('{count, plural, one {# change waiting} other {# changes waiting}}', ['count' => (int) $pending], 'quire-admin')));
        }

        return new Markup($html, 'UTF-8');
    }

    private function dateCell(Content $item): Markup
    {
        return self::time($item->updatedAt);
    }

    private static function time(\DateTimeImmutable $at): Markup
    {
        return new Markup(\sprintf('<time datetime="%s">%s</time>', $at->format(\DATE_ATOM), $at->format('Y-m-d H:i')), 'UTF-8');
    }

    private static function fieldValue(mixed $value): string
    {
        return match (true) {
            null === $value, '' === $value => '—',
            \is_bool($value) => $value ? '✓' : '✗',
            \is_scalar($value) => (string) $value,
            \is_array($value) => implode(', ', array_map(static fn (mixed $part): string => \is_scalar($part) ? (string) $part : (string) json_encode($part), $value)),
            default => (string) json_encode($value),
        };
    }
}
