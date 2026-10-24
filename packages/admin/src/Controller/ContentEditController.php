<?php

declare(strict_types=1);

namespace Quire\Admin\Controller;

use Quire\Admin\Content\ContentLists;
use Quire\Admin\Content\Form\ContentForm;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Exception\ContentConflictException;
use Quire\Content\Exception\ContentOperationCancelledException;
use Quire\Content\Exception\InvalidContentException;
use Quire\Content\Exception\TransitionNotAllowedException;
use Quire\Content\Status;
use Quire\Content\Type\ContentTypeDefinition;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Formatter\Formatter;
use Quire\Settings\Settings;
use Quire\User\CurrentUser;
use Quire\User\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
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
 * The editor: a form for a new item or an existing one, generated from its content type.
 *
 * One form, whose buttons say what to do: save, save and take a transition (e.g. publish), or show a
 * preview without saving. It works without JavaScript; with it, the body gets a Markdown editor with a
 * live preview, and relations a search. Saving an item that someone else saved since it was opened
 * shows the form again, with what was entered, and saves nothing (CNT-024).
 *
 * Until changes to published items become pending revisions, saving a published or scheduled item
 * changes what visitors see, so it takes the ability to publish.
 *
 * @internal
 */
final readonly class ContentEditController
{
    public const string CSRF_TOKEN = 'quire_admin_content_editor';

    /** Items a relation search returns */
    private const int SEARCH_RESULTS = 20;

    public function __construct(
        private ContentTypeRegistry $types,
        private ContentRepository $content,
        private ContentWriter $writer,
        private ContentLists $lists,
        private ContentForm $form,
        private Formatter $formatter,
        private Security $security,
        private CurrentUser $users,
        private UserRepository $userRepository,
        private Settings $settings,
        private UrlGeneratorInterface $urls,
        private CsrfTokenManagerInterface $csrf,
        private TranslatorInterface $translator,
        private Environment $twig,
    ) {
    }

    public function new(string $type): Response
    {
        $definition = $this->type($type);
        $this->assertCanCreate($definition);

        return $this->page($definition, null, [...$this->form->values(null), 'author_id' => $this->users->get()?->id], []);
    }

    public function create(Request $request, string $type): Response
    {
        $definition = $this->type($type);
        $this->assertCanCreate($definition);
        $this->assertToken($request);
        ['values' => $values, 'errors' => $errors] = $this->form->read($definition, $request);
        $intent = $request->request->getString('intent', 'save');

        if ('preview' === $intent) {
            return $this->page($definition, null, $values, $errors, preview: $this->preview($values['body'], null));
        }

        if ([] !== $errors) {
            return $this->page($definition, null, $values, $errors, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $item = $this->writer->create($this->form->create($definition, $values, $this->users->get()?->id));
        } catch (InvalidContentException $e) {
            return $this->page($definition, null, $values, $e->violations, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ContentOperationCancelledException $e) {
            return $this->page($definition, null, $values, ['' => $e->getMessage()], status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->afterSave($request, $definition, $item, $intent, $this->translator->trans('Created.', [], 'quire-admin'));
    }

    public function edit(string $type, string $id): Response
    {
        $definition = $this->type($type);
        $item = $this->item($definition, $id);

        return $this->page($definition, $item, $this->form->values($item), []);
    }

    public function save(Request $request, string $type, string $id): Response
    {
        $definition = $this->type($type);
        $item = $this->item($definition, $id);
        $this->assertToken($request);
        ['values' => $values, 'errors' => $errors] = $this->form->read($definition, $request);
        $intent = $request->request->getString('intent', 'save');
        // The version the editor opened: changes saved since are someone else's.
        $opened = $request->request->getInt('version', $item->version);

        if ('preview' === $intent) {
            return $this->page($definition, $item, $values, $errors, preview: $this->preview($values['body'], $item), version: $opened);
        }

        if (!$this->maySave($definition, $item)) {
            throw new AccessDeniedHttpException($this->translator->trans('Changes to this item go live, which you may not do.', [], 'quire-admin'));
        }

        if ([] !== $errors) {
            return $this->page($definition, $item, $values, $errors, status: Response::HTTP_UNPROCESSABLE_ENTITY, version: $opened);
        }

        $command = $this->form->update($definition, $item, $opened, $values, $this->users->get()?->id);

        try {
            $saved = $command->isEmpty() ? $item : $this->writer->update($command);
        } catch (ContentConflictException) {
            // With the current version, so saving again replaces the other changes, knowingly.
            return $this->page($definition, $item, $values, [], status: Response::HTTP_CONFLICT, conflict: true);
        } catch (InvalidContentException $e) {
            return $this->page($definition, $item, $values, $e->violations, status: Response::HTTP_UNPROCESSABLE_ENTITY, version: $opened);
        } catch (ContentOperationCancelledException $e) {
            return $this->page($definition, $item, $values, ['' => $e->getMessage()], status: Response::HTTP_UNPROCESSABLE_ENTITY, version: $opened);
        }

        return $this->afterSave($request, $definition, $saved, $intent, $this->translator->trans('Saved.', [], 'quire-admin'));
    }

    /**
     * The body as the site shows it, for the live preview.
     */
    public function previewFragment(Request $request, string $type): Response
    {
        $definition = $this->type($type);
        $this->assertToken($request);
        $id = $request->request->getString('id');
        $item = '' === $id ? null : $this->item($definition, $id);

        return new Response($this->preview(str_replace("\r\n", "\n", $request->request->getString('body')), $item));
    }

    /**
     * Items of the given types that match the words, for a relation's search: those the user may see
     * in the types' lists, or the published ones.
     */
    public function search(Request $request): JsonResponse
    {
        $results = [];
        $terms = trim($request->query->getString('q'));

        foreach (array_filter(explode(',', $request->query->getString('types')), $this->types->has(...)) as $type) {
            $definition = $this->types->get($type);
            $query = $this->lists->canList($definition) ? $this->lists->query($definition) : $this->content->query($type);

            foreach ($query->search($terms, prefix: true)->limit(self::SEARCH_RESULTS)->get() as $item) {
                $rank = $item->attributes['search_rank'] ?? 0.0;
                $results[] = ['id' => $item->id, 'label' => \sprintf('%s (%s%s)', $item->title, $definition->label, Status::PUBLISHED === $item->status ? '' : ', '.$this->lists->statusLabel($item->status)), 'rank' => is_numeric($rank) ? (float) $rank : 0.0];
            }
        }

        usort($results, static fn (array $a, array $b): int => $b['rank'] <=> $a['rank']);

        return new JsonResponse(array_map(static fn (array $result): array => ['id' => $result['id'], 'label' => $result['label']], \array_slice($results, 0, self::SEARCH_RESULTS)));
    }

    private function afterSave(Request $request, ContentTypeDefinition $type, Content $item, string $intent, string $message): RedirectResponse
    {
        $session = $request->getSession();
        \assert($session instanceof FlashBagAwareSessionInterface);

        if (str_starts_with($intent, 'transition:')) {
            $transition = substr($intent, \strlen('transition:'));

            if (!\array_key_exists($intent, $this->transitions($type, $item))) {
                $session->getFlashBag()->add('success', $message);
                $session->getFlashBag()->add('error', $this->translator->trans('This isn\'t possible for it now.', [], 'quire-admin'));

                return $this->toEditor($type, $item);
            }

            try {
                $at = Status::SCHEDULE === $transition ? $this->publishAt($request) : null;
                $item = $this->writer->transition($item->id, $item->version, $transition, $at);
                $message = $this->translator->trans('Saved, and the status is now: {status}.', ['status' => $this->lists->statusLabel($item->status)], 'quire-admin');
            } catch (TransitionNotAllowedException|InvalidContentException|ContentOperationCancelledException $e) {
                $session->getFlashBag()->add('success', $message);
                $session->getFlashBag()->add('error', $e instanceof InvalidContentException ? implode(' ', $e->violations) : $e->getMessage());

                return $this->toEditor($type, $item);
            }
        }

        $session->getFlashBag()->add('success', $message);

        return $this->toEditor($type, $item);
    }

    private function toEditor(ContentTypeDefinition $type, Content $item): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate('quire_admin_content_edit', ['type' => $type->id, 'id' => $item->id]), Response::HTTP_SEE_OTHER);
    }

    /**
     * @throws InvalidContentException
     */
    private function publishAt(Request $request): \DateTimeImmutable
    {
        $text = $request->request->getString('publish_at');

        try {
            $timezone = new \DateTimeZone($this->settings->getString('site.timezone'));
        } catch (\Exception) {
            $timezone = new \DateTimeZone('UTC');
        }

        $at = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $text, $timezone);

        return false !== $at ? $at : throw InvalidContentException::single('published_at', $this->translator->trans('Choose when to publish it.', [], 'quire-admin'));
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $errors
     */
    private function page(ContentTypeDefinition $type, ?Content $item, array $values, array $errors, int $status = Response::HTTP_OK, ?string $preview = null, bool $conflict = false, ?int $version = null): Response
    {
        $fields = [];

        foreach ($this->form->groups($type) as $group => $definitions) {
            foreach ($definitions as $field) {
                $error = $errors['fields.'.$field->name] ?? null;
                $fields[$group][] = [
                    'field' => $field,
                    'label' => $field->label ?? ucfirst(str_replace('_', ' ', $field->name)),
                    'control' => $this->form->control($field, $error, $this->form->widget($field)),
                    'html' => $this->form->render($field, \is_array($values['fields'] ?? null) ? $values['fields'][$field->name] ?? null : null, $error),
                    'error' => $error,
                ];
            }
        }

        $editAny = $this->security->isGranted(ContentLists::ability($type, 'edit_any'));

        return new Response($this->twig->render('@quire_admin/content/edit.html.twig', [
            'type' => $type,
            'item' => $item,
            'version' => $version ?? $item?->version,
            'values' => $values,
            'errors' => $errors,
            'fields' => $fields,
            'transitions' => $item instanceof Content ? $this->transitions($type, $item) : $this->newTransitions($type),
            'can_save' => !$item instanceof Content || $this->maySave($type, $item),
            'authors' => $type->has('author') && $editAny ? $this->authors(\is_string($values['author_id'] ?? null) ? $values['author_id'] : null) : [],
            'parents' => $type->has('hierarchical') ? $this->parents($type, $item) : [],
            'preview' => $preview,
            'conflict' => $conflict,
            'timezone' => $this->settings->getString('site.timezone'),
        ]), $status);
    }

    /**
     * The transitions offered next to "Save" for the item, as list actions take them.
     *
     * @return array<string, string> Labels, by "transition:<name>"
     */
    private function transitions(ContentTypeDefinition $type, Content $item): array
    {
        $actions = array_filter($this->lists->actions($type, $item), static fn (string $action): bool => str_starts_with($action, 'transition:'), \ARRAY_FILTER_USE_KEY);

        // Scheduling needs a date, which the editor asks for.
        if (Status::DRAFT === $item->status && $type->has('scheduling') && $this->security->isGranted(ContentLists::ability($type, 'publish'))) {
            $actions['transition:'.Status::SCHEDULE] = $this->translator->trans('transition.schedule', [], 'quire-admin');
        }

        return $actions;
    }

    /**
     * @return array<string, string>
     */
    private function newTransitions(ContentTypeDefinition $type): array
    {
        // What a new item is once it's saved: a draft of the user's.
        $now = new \DateTimeImmutable();
        $draft = new Content(
            id: '00000000-0000-0000-0000-000000000000', type: $type->id, status: Status::DRAFT, locale: $this->settings->getString('site.locale'),
            translationGroupId: null, title: '', slug: '', path: '', parentId: null, position: 0, excerpt: null, body: '',
            bodyFormat: 'markdown', fields: [], authorId: $this->users->get()?->id, version: 1, publishedAt: null,
            createdAt: $now, updatedAt: $now, deletedAt: null,
        );

        return $this->transitions($type, $draft);
    }

    /**
     * Saving changes what visitors see when the item is published or scheduled.
     */
    private function maySave(ContentTypeDefinition $type, Content $item): bool
    {
        return !\in_array($item->status, [Status::PUBLISHED, Status::SCHEDULED], true)
            || $this->security->isGranted(ContentLists::ability($type, 'publish'));
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function authors(?string $current): array
    {
        $authors = [];

        foreach ($this->userRepository->all(limit: 200) as $user) {
            $authors[$user->id] = $user->displayName;
        }

        if (null !== $current && !isset($authors[$current]) && ($user = $this->userRepository->find($current)) instanceof \Quire\User\User) {
            $authors[$user->id] = $user->displayName;
        }

        asort($authors, \SORT_NATURAL | \SORT_FLAG_CASE);

        return array_map(static fn (string $id, string $name): array => ['id' => $id, 'label' => $name], array_keys($authors), $authors);
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function parents(ContentTypeDefinition $type, ?Content $item): array
    {
        $parents = [];

        foreach ($this->lists->query($type)->orderBy('title')->limit(500)->get() as $candidate) {
            if (!$item instanceof Content || ($candidate->id !== $item->id && !str_starts_with($candidate->path.'/', $item->path.'/'))) {
                $parents[] = ['id' => $candidate->id, 'label' => $candidate->title];
            }
        }

        return $parents;
    }

    private function preview(string $body, ?Content $item): string
    {
        try {
            $html = $this->formatter->render($body, $item->bodyFormat ?? 'markdown', 'content', $item);

            // The preview is part of the editor's page: its IDs (e.g. of headings) mustn't be the form's.
            return (string) preg_replace('/\b(id="|href="#)/', '$1preview-', $html);
        } catch (\Throwable $e) {
            return \sprintf('<p class="notice notice--error">%s</p>', htmlspecialchars($this->translator->trans('The preview failed: {problem}', ['problem' => $e->getMessage()], 'quire-admin')));
        }
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

    private function item(ContentTypeDefinition $type, string $id): Content
    {
        $item = $this->lists->query($type, withTrashed: true)->where('id', $id)->first();

        if (!$item instanceof Content) {
            throw new NotFoundHttpException(\sprintf('There\'s no %s with the ID "%s" that you may edit.', strtolower($type->label), $id));
        }

        $editAny = $this->security->isGranted(ContentLists::ability($type, 'edit_any'));

        if (!$editAny && !$this->security->isGranted(ContentLists::ability($type, 'edit_own'), $item)) {
            throw new AccessDeniedHttpException(\sprintf('You may not edit this %s.', strtolower($type->label)));
        }

        return $item;
    }

    private function assertCanCreate(ContentTypeDefinition $type): void
    {
        if (!$this->security->isGranted(ContentLists::ability($type, 'create'))) {
            throw new AccessDeniedHttpException(\sprintf('You may not create %s.', strtolower($type->pluralLabel)));
        }
    }

    private function assertToken(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN, $request->request->getString('_token')))) {
            throw new AccessDeniedHttpException('The form expired. Reload the page and try again.');
        }
    }
}
