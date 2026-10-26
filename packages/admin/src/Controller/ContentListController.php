<?php

declare(strict_types=1);

namespace Quire\Admin\Controller;

use Quire\Admin\Content\ContentBoard;
use Quire\Admin\Content\ContentCalendar;
use Quire\Admin\Content\ContentFacets;
use Quire\Admin\Content\ContentLists;
use Quire\Admin\Content\Job\ApplyToMatching;
use Quire\Admin\Content\ListState;
use Quire\Admin\Content\NextSteps;
use Quire\Admin\Preferences\AdminPreferences;
use Quire\Admin\Preferences\UnderTheHood;
use Quire\Admin\Twig\RelativeTime;
use Quire\Content\Check\CheckReport;
use Quire\Content\Check\CheckResults;
use Quire\Content\Content;
use Quire\Content\Query\ContentPage;
use Quire\Content\Type\ContentTypeDefinition;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\User\CurrentUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The content of a type: a list with tabs, facets, search and the next step for each item; a board of
 * what's in motion; and a calendar. Actions on items are one form: a button of a row acts on its item,
 * the selection bar on the selected ones, or on all that match.
 *
 * What it shows is its URL (see {@see ListState}), so it can be bookmarked and works without
 * JavaScript. With it, the next page comes as you scroll, as a Turbo Stream that appends its rows.
 *
 * @internal
 *
 * @phpstan-type Row array{item: Content, cells: list<string|\Stringable>, actions: array<string, string>, next: ?\Quire\Admin\Content\NextStep, badges: list<array{label: string, kind: string}>, group: ?array{key: string, label: string, count: ?int}}
 */
final readonly class ContentListController
{
    public const string CSRF_TOKEN = 'quire_admin_content_list';

    /** More items than this are acted on in the background */
    public const int BACKGROUND_FROM = 100;

    /** Sorting by these dates groups the list by month */
    private const array GROUPED = ['updated_at', 'published_at', 'created_at'];

    public function __construct(
        private ContentLists $lists,
        private ContentFacets $facets,
        private NextSteps $nextSteps,
        private ContentBoard $board,
        private ContentCalendar $calendar,
        private CheckResults $checks,
        private ContentTypeRegistry $types,
        private UrlGeneratorInterface $urls,
        private CsrfTokenManagerInterface $csrf,
        private TranslatorInterface $translator,
        private Environment $twig,
        private Security $security,
        private CurrentUser $users,
        private MessageBusInterface $bus,
        private UnderTheHood $hood,
        private AdminPreferences $preferences,
        private RelativeTime $time,
    ) {
    }

    public function __invoke(Request $request, string $type): Response
    {
        $definition = $this->type($type);
        $aliases = $this->facets->aliases($definition);
        $state = ListState::fromRequest($request, $this->lists->facets($definition), array_keys($this->lists->filters($definition)), $aliases);
        $common = [
            'type' => $definition,
            'state' => $state,
            'can_create' => $this->security->isGranted(ContentLists::ability($definition, 'create')),
            'total' => $this->lists->query($definition)->count(),
            'modes' => array_map(fn (string $mode): array => ['id' => $mode, 'url' => $this->url($definition, $state->with(page: 1, mode: $mode)), 'current' => $mode === $state->mode], ListState::MODES),
            'density' => $this->preferences->get(AdminPreferences::DENSITY),
            'back' => $request->getRequestUri(),
        ];

        if ('board' === $state->mode) {
            return new Response($this->twig->render('@quire_admin/content/board.html.twig', [
                ...$common,
                'columns' => array_map(fn (array $column): array => [
                    ...$column,
                    'url' => $this->url($definition, $state->with(page: 1, facets: [...$state->facets, 'status' => ['live' === $column['id'] ? 'published' : $column['id']]], mode: 'list')),
                ], $this->board->columns($definition, $state)),
            ]));
        }

        if ('calendar' === $state->mode) {
            $month = $this->calendar->month($definition, $state, $state->month);

            return new Response($this->twig->render('@quire_admin/content/calendar.html.twig', [
                ...$common,
                'calendar' => $month,
                'previous_url' => $this->url($definition, $state->with(month: $month['previous'])),
                'next_url' => $this->url($definition, $state->with(month: $month['next'])),
                'today_url' => $this->url($definition, $state->thisMonth()),
            ]));
        }

        $page = $this->lists->page($definition, $state);
        [$sortField, $sortDirection] = $this->lists->sort($definition, $state->sort, '' !== trim($state->q));
        $columns = array_map(fn (array $column): array => [
            ...$column,
            'sorted' => null !== $sortField && $column['sort'] === $sortField ? $sortDirection : null,
            'sort_url' => null === $column['sort'] ? null : $this->url($definition, $state->with(sort: ($column['sort'] === $sortField && 'asc' === $sortDirection ? '-' : '').$column['sort'], page: 1)),
        ], $this->lists->columns($definition));
        $rows = $this->rows($definition, $page, $state, $columns, $sortField, $request->query->getString('group'));
        $next = $page->hasNextPage() ? $this->url($definition, $state->with(page: $page->page + 1)) : null;
        $lastGroup = [] === $rows ? null : array_last($rows)['group']['key'] ?? null;
        $variables = [
            ...$common,
            'columns' => $columns,
            'rows' => $rows,
            'page' => $page,
            'trash' => ContentLists::TRASH === $state->view,
            'next_url' => $next,
            'more_url' => null === $next ? null : $next.(null === $lastGroup ? '' : '&group='.rawurlencode($lastGroup)),
            'last_group' => $lastGroup,
        ];

        // The next page, as you scroll: its rows, appended.
        if ($page->page > 1 && str_contains($request->headers->get('Accept', ''), 'text/vnd.turbo-stream.html')) {
            return new Response($this->twig->render('@quire_admin/content/_more.stream.html.twig', $variables), headers: ['Content-Type' => 'text/vnd.turbo-stream.html; charset=UTF-8']);
        }

        $response = new Response($this->twig->render('@quire_admin/content/list.html.twig', [
            ...$variables,
            'views' => array_map(fn (array $view): array => [...$view, 'url' => $this->url($definition, new ListState(view: $view['id'], mode: $state->mode)), 'current' => $view['id'] === $state->view], $this->lists->views($definition)),
            'groups' => $this->facetGroups($definition, $state),
            'chips' => $this->chips($definition, $state),
            'syntax' => $this->hood->isOn() ? $state->syntax($aliases) : '',
            'filters' => $this->lists->filters($definition),
            'bulk_actions' => $this->lists->bulkActions($definition, ContentLists::TRASH === $state->view),
            'previous_url' => $page->hasPreviousPage() ? $this->url($definition, $state->with(page: min($page->page, $page->pageCount() + 1) - 1)) : null,
            'action_url' => $this->urls->generate('quire_admin_content_action', ['type' => $definition->id]),
            'state_query' => http_build_query($state->toQuery()),
        ]));

        // A page past the last one, e.g. after the items on it were deleted, still shows the list.
        return $page->isOutOfRange() ? $response->setStatusCode(Response::HTTP_NOT_FOUND) : $response;
    }

    public function act(Request $request, string $type): RedirectResponse
    {
        $definition = $this->type($type);

        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN, $request->request->getString('_token')))) {
            throw new AccessDeniedHttpException('The form expired. Reload the page and try again.');
        }

        parse_str($request->request->getString('state'), $query);
        $state = ListState::fromRequest(new Request($query), $this->lists->facets($definition), array_keys($this->lists->filters($definition)), $this->facets->aliases($definition));
        $back = new RedirectResponse($this->url($definition, $state), Response::HTTP_SEE_OTHER);
        $session = $request->getSession();
        \assert($session instanceof FlashBagAwareSessionInterface);
        $row = $request->request->getString('row');

        if ('' !== $row && str_contains($row, '|')) {
            [$action, $id] = explode('|', $row, 2);
            $ids = [$id];
        } else {
            $action = $request->request->getString('action');
            $ids = array_values(array_filter($request->request->all('ids'), \is_string(...)));
        }

        if ('' !== $action && '' === $row && $request->request->getBoolean('all_matching')) {
            $matching = $this->lists->filtered($definition, $state);
            $count = $matching->count();
            $user = $this->users->get();

            if ($count > self::BACKGROUND_FROM && $user instanceof \Quire\User\User) {
                $this->bus->dispatch(new ApplyToMatching($definition->id, $action, $matching->toArray(), $user->id));
                $session->getFlashBag()->add('success', $this->translator->trans('list.background', ['action' => $this->lists->bulkActions($definition, ContentLists::TRASH === $state->view)[$action] ?? $action, 'count' => $count], 'quire-admin'));

                return $back;
            }

            $ids = array_map(static fn (Content $item): string => $item->id, $matching->limit(self::BACKGROUND_FROM)->get());
        }

        if ('' === $action || [] === $ids) {
            $session->getFlashBag()->add('warning', $this->translator->trans('Choose an action and the items to take it on.', [], 'quire-admin'));

            return $back;
        }

        $result = $this->lists->apply($definition, $action, $ids);

        if ($result['done'] > 0) {
            $session->getFlashBag()->add('success', $this->translator->trans('list.done', ['action' => $result['label'], 'count' => $result['done']], 'quire-admin'));
        }

        foreach ($result['failed'] as $failure) {
            $session->getFlashBag()->add('error', $this->translator->trans('list.failed', ['action' => $result['label'], 'title' => $failure['title'], 'reason' => $failure['reason']], 'quire-admin'));
        }

        return $back;
    }

    /**
     * @param list<array{id: string, label: string, sort: ?string, value: \Closure(Content): (string|\Stringable)}> $columns
     *
     * @return list<Row>
     */
    private function rows(ContentTypeDefinition $type, ContentPage $page, ListState $state, array $columns, ?string $sortField, string $previousGroup): array
    {
        $reports = $this->checks->forItems(array_map(static fn (Content $item): string => $item->id, $page->items));
        $extensions = $this->lists->nextStepExtensions($type);
        $grouped = \in_array($sortField, self::GROUPED, true) && '' === trim($state->q);
        $counts = $grouped ? $this->lists->filtered($type, $state)->countBy($sortField, per: 'month', limit: 1000) : [];
        $timezone = $this->time->timezone();
        $rows = [];

        foreach ($page->items as $item) {
            $actions = $this->lists->actions($type, $item);
            $report = $reports[$item->id] ?? new CheckReport();
            $group = null;

            if ($grouped) {
                $at = match ($sortField) {
                    'published_at' => $item->publishedAt,
                    'created_at' => $item->createdAt,
                    default => $item->updatedAt,
                };
                $key = $at?->setTimezone($timezone)->format('Y-m') ?? '';

                // A month that goes on from the previous page has its heading there.
                if ($key !== (array_last($rows)['group']['key'] ?? $previousGroup)) {
                    $group = ['key' => $key, 'label' => null === $at ? $this->translator->trans('Without a date', [], 'quire-admin') : $this->monthLabel($at->setTimezone($timezone)), 'count' => $counts[$key] ?? null];
                } else {
                    $group = ['key' => $key, 'label' => '', 'count' => null];
                }
            }

            $rows[] = [
                'item' => $item,
                'cells' => array_map(static fn (array $column): string|\Stringable => $column['value']($item), $columns),
                'actions' => $actions,
                'next' => $this->nextSteps->for($type, $item, $report, $actions, $extensions, $this->lists->mayEdit($type, $item)),
                'badges' => $this->badges($item, $report),
                'group' => $group,
            ];
        }

        return $rows;
    }

    /**
     * What's to know about an item at a glance, after its title: changes waiting, and the checks' findings.
     *
     * @return list<array{label: string, kind: string}>
     */
    private function badges(Content $item, CheckReport $report): array
    {
        $badges = [];
        $pending = $item->attributes['pending_changes'] ?? 0;

        if (is_numeric($pending) && (int) $pending > 0) {
            $badges[] = ['label' => $this->translator->trans('{count, plural, one {changes waiting} other {# changes waiting}}', ['count' => (int) $pending], 'quire-admin'), 'kind' => 'review'];
        }

        $findings = $report->results;

        if ([] !== $findings) {
            $kind = $report->hasErrors() ? 'err' : 'warn';
            // One finding is named, e.g. "Broken link"; more are counted.
            $badges[] = 1 === \count($findings)
                ? ['label' => $this->facets->checkLabel($findings[0]->check, $findings[0]->label), 'kind' => $kind]
                : ['label' => $this->translator->trans('{count} to check', ['count' => \count($findings)], 'quire-admin'), 'kind' => $kind];
        }

        return $badges;
    }

    /**
     * The facets with a URL for each value, that chooses or unchooses it.
     *
     * @return list<array<string, mixed>>
     */
    private function facetGroups(ContentTypeDefinition $type, ListState $state): array
    {
        return array_map(fn (array $group): array => [
            ...$group,
            'options' => array_map(fn (array $option): array => [
                ...$option,
                'checked' => $state->has($group['id'], $option['value']),
                'url' => $this->url($type, $state->toggled($group['id'], $option['value'])),
            ], $group['options']),
        ], $this->facets->groups($type, $state));
    }

    /**
     * The chosen facets' values, as chips in the search box that take them off.
     *
     * @return list<array{label: string, url: string}>
     */
    private function chips(ContentTypeDefinition $type, ListState $state): array
    {
        $chips = [];

        foreach ($state->facets as $facet => $values) {
            foreach ($values as $value) {
                $chips[] = ['label' => $this->chipLabel($type, $facet, $value), 'url' => $this->url($type, $state->toggled($facet, $value))];
            }
        }

        return $chips;
    }

    private function chipLabel(ContentTypeDefinition $type, string $facet, string $value): string
    {
        $field = str_starts_with($facet, 'fields.') ? $type->field(substr($facet, \strlen('fields.'))) : null;
        $name = match (true) {
            $field instanceof \Quire\Content\Field\FieldDefinition => $field->label ?? ucfirst(str_replace('_', ' ', $field->name)),
            'status' === $facet => $this->translator->trans('Where it is', [], 'quire-admin'),
            'checks' === $facet => $this->translator->trans('Something to check', [], 'quire-admin'),
            default => $this->translator->trans('Language', [], 'quire-admin'),
        };
        $shown = match ($facet) {
            'status' => 'review' === $value ? $this->translator->trans('In review', [], 'quire-admin') : $this->lists->statusLabel($value),
            'checks' => 'any' === $value ? $this->translator->trans('any', [], 'quire-admin') : $this->facets->checkLabel($value, $value),
            default => $value,
        };

        return $name.': '.$shown;
    }

    private function monthLabel(\DateTimeImmutable $at): string
    {
        $formatter = new \IntlDateFormatter($this->translator->getLocale(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $at->getTimezone(), null, 'LLLL y');

        return (string) $formatter->format($at);
    }

    private function type(string $id): ContentTypeDefinition
    {
        if (!$this->types->has($id)) {
            throw new NotFoundHttpException(\sprintf('There\'s no content type "%s".', $id));
        }

        $type = $this->types->get($id);

        if (!$this->lists->canList($type)) {
            throw new AccessDeniedHttpException(\sprintf('You may not edit any %s.', strtolower($type->pluralLabel)));
        }

        return $type;
    }

    private function url(ContentTypeDefinition $type, ListState $state): string
    {
        return $this->urls->generate('quire_admin_content', ['type' => $type->id, ...$state->toQuery()]);
    }
}
