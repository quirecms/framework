<?php

declare(strict_types=1);

namespace Quire\Admin\Controller;

use Quire\Admin\Content\ContentLists;
use Quire\Content\Type\ContentTypeDefinition;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Content\Workflow\ContentWorkflow;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The list of a content type's items, and the actions taken on them from it.
 *
 * The list is a page of its URL (view, search, filters, sort, page), so it can be bookmarked and works
 * without JavaScript. Actions are one form: a button of a row acts on its item, "Apply" on the selected
 * ones; then the list shows again, as it was, with what happened.
 *
 * @internal
 */
final readonly class ContentListController
{
    public const string CSRF_TOKEN = 'quire_admin_content_list';

    /** The query parameters of a list, kept when going back to it */
    private const array STATE = ['view', 'q', 'sort', 'page', 'filter'];

    public function __construct(
        private ContentLists $lists,
        private ContentTypeRegistry $types,
        private ContentWorkflow $workflow,
        private UrlGeneratorInterface $urls,
        private CsrfTokenManagerInterface $csrf,
        private TranslatorInterface $translator,
        private Environment $twig,
        private Security $security,
    ) {
    }

    public function __invoke(Request $request, string $type): Response
    {
        $definition = $this->type($type);
        $state = $this->state($request, $definition);
        $page = $this->lists->page($definition, $state['view'], $state['q'], $state['sort'], $state['filter'], $state['page']);
        [$sortField, $sortDirection] = $this->lists->sort($definition, $state['sort'], '' !== trim($state['q']));
        $trash = ContentLists::TRASH === $state['view'];

        $columns = array_map(fn (array $column): array => [
            ...$column,
            'sorted' => null !== $sortField && $column['sort'] === $sortField ? $sortDirection : null,
            'sort_url' => null === $column['sort'] ? null : $this->url($definition, [...$state, 'page' => 1, 'sort' => ($column['sort'] === $sortField && 'asc' === $sortDirection ? '-' : '').$column['sort']]),
        ], $this->lists->columns($definition));

        $rows = [];

        foreach ($page->items as $item) {
            $rows[] = [
                'item' => $item,
                'cells' => array_map(static fn (array $column): string|\Stringable => $column['value']($item), $columns),
                'actions' => $this->lists->actions($definition, $item),
            ];
        }

        $views = array_map(fn (array $view): array => [...$view, 'url' => $this->url($definition, ['view' => $view['id']]), 'current' => $view['id'] === $state['view']], $this->lists->views($definition));

        $response = new Response($this->twig->render('@quire_admin/content/list.html.twig', [
            'type' => $definition,
            'can_create' => $this->security->isGranted(ContentLists::ability($definition, 'create')),
            'views' => $views,
            'state' => $state,
            'filters' => $this->lists->filters($definition),
            'columns' => $columns,
            'rows' => $rows,
            'page' => $page,
            'trash' => $trash,
            'bulk_actions' => $this->lists->bulkActions($definition, $trash),
            'previous_url' => $page->hasPreviousPage() ? $this->url($definition, [...$state, 'page' => min($page->page, $page->pageCount() + 1) - 1]) : null,
            'next_url' => $page->hasNextPage() ? $this->url($definition, [...$state, 'page' => $page->page + 1]) : null,
            'action_url' => $this->urls->generate('quire_admin_content_action', ['type' => $definition->id]),
            'state_query' => http_build_query($this->query($state)),
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

        $row = $request->request->getString('row');

        if ('' !== $row && str_contains($row, '|')) {
            [$action, $id] = explode('|', $row, 2);
            $ids = [$id];
        } else {
            $action = $request->request->getString('action');
            $ids = array_values(array_filter($request->request->all('ids'), \is_string(...)));
        }

        parse_str($request->request->getString('state'), $query);
        $back = new RedirectResponse($this->urls->generate('quire_admin_content', ['type' => $definition->id, ...$this->query($this->state(new Request($query), $definition))]), Response::HTTP_SEE_OTHER);
        $session = $request->getSession();
        \assert($session instanceof FlashBagAwareSessionInterface);

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

    /**
     * The list's state from the URL, with anything unknown left out.
     *
     * @return array{view: string, q: string, sort: string, page: int, filter: array<string, string>}
     */
    private function state(Request $request, ContentTypeDefinition $type): array
    {
        $view = $request->query->getString('view', 'all');
        $views = ['all', ContentLists::TRASH, ...array_keys($this->workflow->statuses())];
        $filters = $request->query->all('filter');

        return [
            'view' => \in_array($view, $views, true) ? $view : 'all',
            'q' => mb_substr(trim($request->query->getString('q')), 0, 200),
            'sort' => $request->query->getString('sort'),
            'page' => max(1, $request->query->getInt('page', 1)),
            'filter' => array_filter(
                array_intersect_key($filters, $this->lists->filters($type)),
                static fn (mixed $value): bool => \is_string($value) && '' !== $value,
            ),
        ];
    }

    /**
     * @param array{view?: string, q?: string, sort?: string, page?: int, filter?: array<string, string>} $state
     */
    private function url(ContentTypeDefinition $type, array $state): string
    {
        return $this->urls->generate('quire_admin_content', ['type' => $type->id, ...$this->query($state)]);
    }

    /**
     * The state as query parameters, without defaults.
     *
     * @param array{view?: string, q?: string, sort?: string, page?: int, filter?: array<string, string>} $state
     *
     * @return array<string, mixed>
     */
    private function query(array $state): array
    {
        $defaults = ['view' => 'all', 'q' => '', 'sort' => '', 'page' => 1, 'filter' => []];

        return array_filter(
            array_intersect_key($state, array_flip(self::STATE)),
            static fn (mixed $value, string $key): bool => $value !== $defaults[$key],
            \ARRAY_FILTER_USE_BOTH,
        );
    }
}
