<?php

declare(strict_types=1);

namespace Quire\Admin\Content;

use Psr\Log\LoggerInterface;
use Quire\Action\Actions;
use Quire\Action\Exception\ActionDeniedException;
use Quire\Admin\Extend\Admin;
use Quire\Admin\Twig\RelativeTime;
use Quire\Content\Check\ContentChecker;
use Quire\Content\Check\Level;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\Field\FieldStorage;
use Quire\Content\Field\Type\RelationField;
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
    public const int PER_PAGE = 50;

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
        private Actions $actions,
        private ContentWorkflow $workflow,
        private Security $security,
        private CurrentUser $users,
        private ContentChecker $checker,
        private RelativeTime $time,
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
     * The tabs of the list, with how many items each has: what needs the current user, their drafts,
     * everything not in the trash, and the trash.
     *
     * @return list<array{id: string, label: string, count: int}>
     */
    public function views(ContentTypeDefinition $type): array
    {
        $labels = [
            'needs-me' => $this->translator->trans('Needs me', [], 'quire-admin'),
            'mine' => $this->translator->trans('My drafts', [], 'quire-admin'),
            'all' => $this->translator->trans('Everything', [], 'quire-admin'),
            self::TRASH => $this->translator->trans('Trash', [], 'quire-admin'),
        ];

        return array_map(fn (string $view): array => ['id' => $view, 'label' => $labels[$view], 'count' => $this->inView($type, $this->query($type), $view)->count()], ListState::VIEWS);
    }

    /**
     * What the list shows for its state: its view, search, facets and the extensions' filters.
     *
     * @param string|null $except A facet to leave out, to count its own values
     */
    public function filtered(ContentTypeDefinition $type, ListState $state, ?string $except = null): ContentQuery
    {
        $query = $this->inView($type, $this->query($type), $state->view)->search($state->q, prefix: true);

        foreach ($state->facets as $facet => $values) {
            if ($facet !== $except) {
                $query = $this->narrow($type, $query, $facet, $values);
            }
        }

        foreach ($this->filters($type) as $id => $filter) {
            if (isset($state->filters[$id]) && \array_key_exists($state->filters[$id], $filter->choices())) {
                $query = $filter->apply($query, $state->filters[$id]);
            }
        }

        return $query;
    }

    /**
     * The facets the list can narrow by: where items are in their lifecycle, findings of the checks, the
     * language when there are several, and the type's filterable fields with few values (choices, yes or
     * no, lists and relations).
     *
     * @return list<string>
     */
    public function facets(ContentTypeDefinition $type): array
    {
        $facets = ['status', 'checks', 'locale'];

        foreach ($type->fields as $field) {
            if ($field->isIndexed() && (\in_array($field->storage(), [FieldStorage::Boolean, FieldStorage::TextList], true) || [] !== $field->choices) || FieldStorage::Relation === $field->storage()) {
                $facets[] = 'fields.'.$field->name;
            }
        }

        return $facets;
    }

    /**
     * Narrows a query to items with any of the values of a facet.
     *
     * @param list<string> $values
     */
    public function narrow(ContentTypeDefinition $type, ContentQuery $query, string $facet, array $values): ContentQuery
    {
        return match (true) {
            'status' === $facet => $this->byStatus($query, $values),
            'checks' === $facet => \in_array('any', $values, true)
                ? $query->withIssues()
                : $query->whereSql('EXISTS (SELECT 1 FROM quire.content_check_result k WHERE k.content_id = c.id AND k.check_id = ANY(CAST(? AS text[])))', ['{'.implode(',', array_map(static fn (string $id): string => '"'.addcslashes($id, '"\\').'"', $values)).'}'], 'checks'),
            'locale' === $facet => $query->where('locale', 'in', $values),
            str_starts_with($facet, 'fields.') => $this->byField($type, $query, $facet, $values),
            default => $query,
        };
    }

    /**
     * One page of the list.
     */
    public function page(ContentTypeDefinition $type, ListState $state): ContentPage
    {
        $query = $this->filtered($type, $state);
        $me = $this->users->get()?->id;

        if ($type->has('author')) {
            $query = $query->select('author_name', 'SELECT u.display_name FROM quire.app_user u WHERE u.id = c.author_id');
        }

        // Changes to published items that aren't published yet; the first waiting for someone else's
        // review, and the state of the current user's own, for the next step.
        $query = $query
            ->select('pending_changes', 'SELECT count(*) FROM quire.content_pending_revision p WHERE p.content_id = c.id')
            ->select('review_pending_id', "SELECT p.id::text FROM quire.content_pending_revision p WHERE p.content_id = c.id AND p.state = 'in_review' AND p.author_id IS DISTINCT FROM CAST(? AS uuid) ORDER BY p.updated_at, p.id LIMIT 1", [$me])
            ->select('own_pending_state', 'SELECT p.state FROM quire.content_pending_revision p WHERE p.content_id = c.id AND p.author_id = CAST(? AS uuid) ORDER BY p.updated_at DESC LIMIT 1', [$me]);

        foreach ($this->extensions($type, 'column') as $column) {
            \assert($column instanceof ContentColumnInterface);
            $query = $column->query($query);
        }

        [$field, $direction] = $this->sort($type, $state->sort, '' !== trim($state->q));

        if (null !== $field) {
            $query = $query->orderBy($field, $direction);
        }

        return $query->paginate($state->page, self::PER_PAGE);
    }

    private function inView(ContentTypeDefinition $type, ContentQuery $query, string $view): ContentQuery
    {
        $me = $this->users->get()?->id;

        return match ($view) {
            self::TRASH => $query->onlyTrashed(),
            'mine' => null === $me ? $query->whereSql('FALSE', field: 'view') : $query->status(Status::DRAFT)->where('author_id', $me),
            'needs-me' => null === $me ? $query->whereSql('FALSE', field: 'view') : $this->needsMe($type, $query, $me),
            default => $query,
        };
    }

    /**
     * What needs the user: changes waiting for their review; their own changes sent back, or that couldn't
     * be published at their time; their items that can't be published as they are.
     */
    private function needsMe(ContentTypeDefinition $type, ContentQuery $query, string $me): ContentQuery
    {
        $conditions = [
            "EXISTS (SELECT 1 FROM quire.content_pending_revision p WHERE p.content_id = c.id AND p.author_id = CAST(? AS uuid) AND p.state = 'changes_requested')",
            'EXISTS (SELECT 1 FROM quire.content_pending_revision p WHERE p.content_id = c.id AND p.publish_problem IS NOT NULL AND (p.scheduled_by = CAST(? AS uuid) OR p.author_id = CAST(? AS uuid)))',
            'c.author_id = CAST(? AS uuid) AND EXISTS (SELECT 1 FROM quire.content_check_result k WHERE k.content_id = c.id AND k.check_id = ANY(CAST(? AS text[])))',
        ];
        $params = [$me, $me, $me, $me, '{'.implode(',', array_map(static fn (string $id): string => '"'.$id.'"', $this->errorChecks())).'}'];

        if ($this->security->isGranted(self::ability($type, 'review'))) {
            $conditions[] = "EXISTS (SELECT 1 FROM quire.content_pending_revision p WHERE p.content_id = c.id AND p.state = 'in_review' AND p.author_id IS DISTINCT FROM CAST(? AS uuid))";
            $params[] = $me;
        }

        return $query->whereSql(implode(' OR ', array_map(static fn (string $condition): string => '('.$condition.')', $conditions)), $params, 'view');
    }

    /**
     * @param list<string> $values Statuses, and "review" for items with changes waiting for a review
     */
    private function byStatus(ContentQuery $query, array $values): ContentQuery
    {
        $statuses = array_values(array_intersect($values, array_keys($this->workflow->statuses())));
        $conditions = [];
        $params = [];

        if ([] !== $statuses) {
            $conditions[] = 'c.status = ANY(CAST(? AS text[]))';
            $params[] = '{'.implode(',', $statuses).'}';
        }

        if (\in_array('review', $values, true)) {
            $conditions[] = "EXISTS (SELECT 1 FROM quire.content_pending_revision p WHERE p.content_id = c.id AND p.state = 'in_review')";
        }

        return $query->whereSql([] === $conditions ? 'FALSE' : implode(' OR ', $conditions), $params, 'status');
    }

    /**
     * @param list<string> $values
     */
    private function byField(ContentTypeDefinition $type, ContentQuery $query, string $facet, array $values): ContentQuery
    {
        $field = $type->field(substr($facet, \strlen('fields.')));

        return match (true) {
            !$field instanceof \Quire\Content\Field\FieldDefinition => $query,
            FieldStorage::TextList === $field->storage() => $query->where($facet, 'contains any', $values),
            FieldStorage::Relation === $field->storage() => $query->where($facet, RelationField::isMultiple($field) ? 'contains any' : 'in', $values),
            FieldStorage::Boolean === $field->storage() => $query->where($facet, 'in', array_values(array_map(static fn (string $value): bool => 'true' === $value, array_intersect($values, ['true', 'false'])))),
            default => $query->where($facet, 'in', $values),
        };
    }

    /**
     * The checks whose findings block publishing now.
     *
     * @return list<string>
     */
    private function errorChecks(): array
    {
        return array_values(array_map(static fn (array $check): string => $check['id'], array_filter($this->checker->checks(), static fn (array $check): bool => Level::Error === $check['level'])));
    }

    /**
     * Where a status is on the lifecycle thread (Quire UI's q-thread): draft, review, scheduled or live.
     */
    public static function stage(string $status): string
    {
        return ['scheduled' => 'scheduled', 'published' => 'live'][$status] ?? 'draft';
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
            ['id' => 'status', 'label' => $this->translator->trans('Where it is', [], 'quire-admin'), 'sort' => null, 'value' => $this->statusCell(...)],
        ];

        if ($type->has('author')) {
            $me = $this->users->get()?->id;
            $you = $this->translator->trans('You', [], 'quire-admin');
            $columns[] = ['id' => 'author', 'label' => $this->translator->trans('Author', [], 'quire-admin'), 'sort' => null, 'value' => static fn (Content $item): string => null !== $me && $item->authorId === $me ? $you : (\is_string($name = $item->attributes['author_name'] ?? null) ? $name : '—')];
        }

        foreach ($type->fields as $field) {
            if ($field->sortable) {
                $columns[] = ['id' => 'fields.'.$field->name, 'label' => $field->label ?? ucfirst(str_replace('_', ' ', $field->name)), 'sort' => 'fields.'.$field->name, 'value' => static fn (Content $item): string => self::fieldValue($item->fields[$field->name] ?? null)];
            }
        }

        $columns[] = ['id' => 'updated_at', 'label' => $this->translator->trans('Updated', [], 'quire-admin'), 'sort' => 'updated_at', 'value' => $this->dateCell(...)];
        $columns[] = ['id' => 'published_at', 'label' => $this->translator->trans('Published', [], 'quire-admin'), 'sort' => 'published_at', 'value' => fn (Content $item): string|Markup => $item->publishedAt instanceof \DateTimeImmutable ? $this->time->html($item->publishedAt) : '—'];

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
            } catch (ContentActionException|ActionDeniedException|\Quire\Content\Exception\ContentConflictException|\Quire\Content\Exception\TransitionNotAllowedException|\Quire\Content\Exception\InvalidContentException|\Quire\Content\Exception\ContentOperationCancelledException $e) {
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
        // Core's through its actions, which check again who may, and do it as the CLI would.
        match (true) {
            str_starts_with($action, 'transition:') => $this->actions->run('content.transition', ['content' => $item->id, 'transition' => substr($action, \strlen('transition:'))]),
            str_starts_with($action, 'extension:') => $this->extensionAction($type, substr($action, \strlen('extension:')))->apply($item),
            \in_array($action, ['trash', 'restore', 'purge'], true) => $this->actions->run('content.'.$action, ['content' => $item->id]),
            default => throw new \LogicException(\sprintf('Unknown action "%s".', $action)),
        };
    }

    public function mayEdit(ContentTypeDefinition $type, Content $item): bool
    {
        return $this->security->isGranted(self::ability($type, 'edit_any')) || $this->security->isGranted(self::ability($type, 'edit_own'), $item);
    }

    /**
     * @return list<NextStepInterface> The extensions' next steps for the type, in order
     */
    public function nextStepExtensions(ContentTypeDefinition $type): array
    {
        return array_values(array_filter($this->extensions($type, 'next-step'), static fn (object $step): bool => $step instanceof NextStepInterface));
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
        $trashed = $item->deletedAt instanceof \DateTimeImmutable;
        // Scheduled items say when, e.g. "Tue 09:00".
        $label = match (true) {
            $trashed => htmlspecialchars($this->translator->trans('In the trash', [], 'quire-admin')),
            Status::SCHEDULED === $item->status && $item->publishedAt instanceof \DateTimeImmutable => \sprintf('<span class="q-sr">%s, </span>%s', htmlspecialchars($this->statusLabel($item->status)), $this->time->html($item->publishedAt)),
            default => htmlspecialchars($this->statusLabel($item->status)),
        };

        // The lifecycle thread (Quire UI's q-status), as {{ ui.status() }} renders it.
        $stage = $trashed ? 'draft' : self::stage($item->status);

        return new Markup(\sprintf('<span class="q-status q-status--%1$s"><span class="q-thread q-thread--%1$s" aria-hidden="true"><i></i><i></i><i></i><i></i></span><span>%2$s</span></span>', $stage, $label), 'UTF-8');
    }

    private function dateCell(Content $item): Markup
    {
        return $this->time->html($item->updatedAt);
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
