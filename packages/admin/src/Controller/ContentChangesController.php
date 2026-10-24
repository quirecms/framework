<?php

declare(strict_types=1);

namespace Quire\Admin\Controller;

use Quire\Admin\Content\ContentLists;
use Quire\Admin\Content\PendingChanges;
use Quire\Content\Command\UpdateContent;
use Quire\Content\Content;
use Quire\Content\ContentWriter;
use Quire\Content\Exception\ContentConflictException;
use Quire\Content\Exception\ContentNotFoundException;
use Quire\Content\Exception\ContentOperationCancelledException;
use Quire\Content\Exception\InvalidContentException;
use Quire\Content\Exception\MergeConflictException;
use Quire\Content\Exception\PendingRevisionConflictException;
use Quire\Content\Revision\Diff;
use Quire\Content\Revision\DiffLine;
use Quire\Content\Revision\PendingRevision;
use Quire\Content\Revision\PendingRevisionRepository;
use Quire\Content\Revision\PendingRevisionWriter;
use Quire\Content\Revision\Revision;
use Quire\Content\Revision\RevisionRepository;
use Quire\Content\Revision\Snapshot;
use Quire\Content\Revision\Verdict;
use Quire\Content\Type\ContentTypeDefinition;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Settings\Settings;
use Quire\User\CurrentUser;
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
 * What changes an item: the changes waiting to be published (a pending revision's diff, whether it merges
 * with what's published, its reviews), the item's history of revisions, and restoring one of them. Also
 * saves the item's address and place, which change it right away.
 *
 * @internal
 *
 * A diff's lines have operation, text, old and new numbers, or operation "skip" and the count of lines left out
 *
 * @phpstan-type DiffView array{properties: list<array{name: string, old: string, new: string}>, lines: list<array<string, int|string|null>>}
 */
final readonly class ContentChangesController
{
    /** Unchanged lines shown around each change of the text */
    private const int CONTEXT = 3;

    public function __construct(
        private ContentTypeRegistry $types,
        private ContentLists $lists,
        private PendingChanges $changes,
        private PendingRevisionRepository $pending,
        private PendingRevisionWriter $pendingWriter,
        private RevisionRepository $revisions,
        private ContentWriter $writer,
        private CurrentUser $users,
        private Settings $settings,
        private UrlGeneratorInterface $urls,
        private CsrfTokenManagerInterface $csrf,
        private TranslatorInterface $translator,
        private Environment $twig,
    ) {
    }

    public function changes(string $type, string $id, string $pending): Response
    {
        $definition = $this->type($type);
        $item = $this->item($definition, $id);
        $revision = $this->pendingRevision($item, $pending);

        return $this->changesPage($definition, $item, $revision);
    }

    public function act(Request $request, string $type, string $id, string $pending): Response
    {
        $definition = $this->type($type);
        $item = $this->item($definition, $id);
        $revision = $this->pendingRevision($item, $pending);
        $this->assertToken($request);
        $intent = $request->request->getString('intent');
        $message = trim($request->request->getString('message'));
        $expected = $request->request->getInt('version', $revision->version);
        $actor = $this->users->get()?->id;
        $flash = $this->flash($request);

        try {
            switch ($intent) {
                case 'approve':
                case 'request_changes':
                case 'comment':
                    if (!$this->changes->mayReview($definition) || ('approve' === $intent && !$this->changes->mayApprove($definition, $revision))) {
                        throw new AccessDeniedHttpException($this->translator->trans('You may not review these changes.', [], 'quire-admin'));
                    }

                    $this->pendingWriter->review($revision->id, $expected, Verdict::from($intent), $actor, '' === $message ? null : $message);
                    $flash->add('success', $this->translator->trans('review.done.'.$intent, [], 'quire-admin'));
                    break;
                case 'publish':
                    $can = $this->changes->canPublish($definition, $revision);

                    if (true !== $can) {
                        throw new AccessDeniedHttpException($can);
                    }

                    $this->writer->publishPendingRevision($revision->id, $expected, $actor);
                    $flash->add('success', $this->translator->trans('The changes are published.', [], 'quire-admin'));

                    return $this->toEditor($definition, $item);
                case 'discard':
                    if (!$this->changes->mayDiscard($definition, $revision)) {
                        throw new AccessDeniedHttpException($this->translator->trans('Only their author, or someone who may publish, discards changes.', [], 'quire-admin'));
                    }

                    $this->pendingWriter->discard($revision->id, $actor);
                    $flash->add('success', $this->translator->trans('The changes are discarded.', [], 'quire-admin'));

                    return $this->toEditor($definition, $item);
                default:
                    throw new NotFoundHttpException(\sprintf('Unknown action "%s".', $intent));
            }
        } catch (PendingRevisionConflictException) {
            $flash->add('error', $this->translator->trans('The changes were saved again while you looked at them. Here they are as they are now.', [], 'quire-admin'));
        } catch (MergeConflictException $e) {
            return $this->changesPage($definition, $item, $revision, Response::HTTP_CONFLICT, $e->conflicts);
        } catch (InvalidContentException $e) {
            $flash->add('error', implode(' ', $e->violations));
        } catch (ContentOperationCancelledException|AccessDeniedHttpException $e) {
            $flash->add('error', $e->getMessage());
        }

        return new RedirectResponse($this->urls->generate('quire_admin_content_changes', ['type' => $definition->id, 'id' => $item->id, 'pending' => $revision->id]), Response::HTTP_SEE_OTHER);
    }

    public function history(string $type, string $id): Response
    {
        $definition = $this->type($type);
        $item = $this->item($definition, $id);
        $revisions = $this->revisions->forContent($item->id);
        $entries = [];

        foreach ($revisions as $index => $revision) {
            $previous = $revisions[$index + 1] ?? null;
            $entries[] = [
                'revision' => $revision,
                'diff' => null === $previous ? null : $this->diffView($definition, Diff::between(Snapshot::of($previous), Snapshot::of($revision))),
                'current' => 0 === $index,
            ];
        }

        return new Response($this->twig->render('@quire_admin/content/history.html.twig', [
            'type' => $definition,
            'item' => $item,
            'entries' => $entries,
            'names' => $this->changes->names([...array_map(static fn (Revision $revision): ?string => $revision->authorId, $revisions), ...array_map(static fn (Revision $revision): ?string => $revision->approvedBy, $revisions)]),
            'changes' => $this->changes->applies($item),
            'timezone' => $this->settings->getString('site.timezone'),
        ]));
    }

    public function restore(Request $request, string $type, string $id): RedirectResponse
    {
        $definition = $this->type($type);
        $item = $this->item($definition, $id);
        $this->assertToken($request);
        $revision = $this->revisions->find($request->request->getString('revision'));
        $flash = $this->flash($request);

        if (!$revision instanceof Revision || $revision->contentId !== $item->id) {
            throw new NotFoundHttpException('The item has no such revision.');
        }

        try {
            if ($this->changes->applies($item)) {
                // A published item: the old version becomes the user's changes, to look at and publish.
                $this->pendingWriter->create($item->id, $this->users->get()?->id, $this->translator->trans('Restore the version of {date}', ['date' => $revision->createdAt->setTimezone($this->timezone())->format('Y-m-d H:i')], 'quire-admin'), $revision->id);
                $flash->add('success', $this->translator->trans('The old version is in your changes now. Publish them to restore it.', [], 'quire-admin'));
            } else {
                $this->writer->restoreRevision($item->id, $revision->id, $item->version, $this->users->get()?->id);
                $flash->add('success', $this->translator->trans('The old version is restored.', [], 'quire-admin'));
            }
        } catch (ContentConflictException) {
            $flash->add('error', $this->translator->trans('The item was saved while you looked at its history. Try again.', [], 'quire-admin'));
        } catch (InvalidContentException $e) {
            $flash->add('error', implode(' ', $e->violations));
        } catch (ContentOperationCancelledException $e) {
            $flash->add('error', $e->getMessage());
        }

        return $this->toEditor($definition, $item);
    }

    /**
     * Saves the address and place of a published item, which aren't part of its changes: they change
     * it right away, so they take the right to publish.
     */
    public function place(Request $request, string $type, string $id): RedirectResponse
    {
        $definition = $this->type($type);
        $item = $this->item($definition, $id);
        $this->assertToken($request);
        $flash = $this->flash($request);

        if (!$this->changes->mayPublish($definition)) {
            throw new AccessDeniedHttpException($this->translator->trans('Publishing takes the right to publish; ask someone who has it.', [], 'quire-admin'));
        }

        $input = $request->request;
        $command = new UpdateContent($item->id, $input->getInt('version', $item->version), actorId: $this->users->get()?->id);
        $slug = trim($input->getString('slug'));

        if ('' !== $slug && $slug !== $item->slug) {
            $command = $command->slug($slug);
        }

        $author = '' === $input->getString('author_id') ? null : $input->getString('author_id');
        $parent = '' === $input->getString('parent_id') ? null : $input->getString('parent_id');

        if ($definition->has('author') && $input->has('author_id') && $author !== $item->authorId) {
            $command = $command->author($author);
        }

        if ($definition->has('hierarchical')) {
            if ($parent !== $item->parentId) {
                $command = $command->parent($parent);
            }

            if (1 === preg_match('/^\d+$/', $input->getString('position')) && (int) $input->getString('position') !== $item->position) {
                $command = $command->position((int) $input->getString('position'));
            }
        }

        try {
            if (!$command->isEmpty()) {
                $this->writer->update($command);
            }

            $flash->add('success', $this->translator->trans('Saved.', [], 'quire-admin'));
        } catch (ContentConflictException) {
            $flash->add('error', $this->translator->trans('The item was saved while you edited it. Check its address and place, and save again.', [], 'quire-admin'));
        } catch (InvalidContentException $e) {
            $flash->add('error', implode(' ', $e->violations));
        } catch (ContentOperationCancelledException $e) {
            $flash->add('error', $e->getMessage());
        }

        return $this->toEditor($definition, $item);
    }

    /**
     * @param array<string, string>|null $conflicts
     */
    private function changesPage(ContentTypeDefinition $type, Content $item, PendingRevision $revision, int $status = Response::HTTP_OK, ?array $conflicts = null): Response
    {
        $reviews = $this->pending->reviews($revision->id);
        $merge = $this->pending->merge($revision, $item);

        return new Response($this->twig->render('@quire_admin/content/changes.html.twig', [
            'type' => $type,
            'item' => $item,
            'pending' => $revision,
            'state' => $this->changes->stateLabel($revision->state),
            'diff' => $this->diffView($type, $revision->diff()),
            'conflicts' => $conflicts ?? ($merge->isClean() ? [] : $merge->conflicts),
            'reviews' => $reviews,
            'names' => $this->changes->names([$revision->authorId, $revision->scheduledBy, ...array_map(static fn (\Quire\Content\Revision\Review $review): ?string => $review->reviewerId, $reviews)]),
            'own' => $this->changes->isOwn($revision),
            'may_review' => $this->changes->mayReview($type),
            'may_approve' => $this->changes->mayApprove($type, $revision),
            'can_publish' => $this->changes->canPublish($type, $revision),
            'may_discard' => $this->changes->mayDiscard($type, $revision),
            'timezone' => $this->settings->getString('site.timezone'),
        ]), $status);
    }

    /**
     * A diff as the page shows it: the changed properties, then the text's lines, with only a few
     * unchanged lines around each change.
     *
     * @return DiffView
     */
    private function diffView(ContentTypeDefinition $type, Diff $diff): array
    {
        $properties = [];

        foreach ($diff->changes as $name => $change) {
            if ('body' !== $name) {
                $field = str_starts_with($name, 'fields.') ? $type->field(substr($name, 7)) : null;
                $properties[] = [
                    'name' => $field->label ?? (str_starts_with($name, 'fields.') ? substr($name, 7) : $this->translator->trans(['title' => 'Title', 'excerpt' => 'Excerpt', 'body_format' => 'Text format'][$name] ?? $name, [], 'quire-admin')),
                    'old' => $this->text($change['old'] ?? null),
                    'new' => $this->text($change['new'] ?? null),
                ];
            }
        }

        $lines = $diff->bodyLines;
        $near = [];

        foreach ($lines as $index => $line) {
            if (DiffLine::SAME !== $line->operation) {
                foreach (range(max(0, $index - self::CONTEXT), min(\count($lines) - 1, $index + self::CONTEXT)) as $shown) {
                    $near[$shown] = true;
                }
            }
        }

        $view = [];
        $skipped = 0;

        foreach ($lines as $index => $line) {
            if (!isset($near[$index])) {
                ++$skipped;

                continue;
            }

            if ($skipped > 0) {
                $view[] = ['operation' => 'skip', 'count' => $skipped];
                $skipped = 0;
            }

            $view[] = ['operation' => $line->operation, 'text' => $line->text, 'old' => $line->oldNumber, 'new' => $line->newNumber];
        }

        if ($skipped > 0 && [] !== $view) {
            $view[] = ['operation' => 'skip', 'count' => $skipped];
        }

        return ['properties' => $properties, 'lines' => $view];
    }

    private function text(mixed $value): string
    {
        return match (true) {
            null === $value, '' === $value => '—',
            \is_bool($value) => $value ? '✓' : '✗',
            \is_scalar($value) => (string) $value,
            default => (string) json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
        };
    }

    private function pendingRevision(Content $item, string $id): PendingRevision
    {
        try {
            $pending = $this->pending->get($id);
        } catch (ContentNotFoundException) {
            throw new NotFoundHttpException($this->translator->trans('These changes are no longer pending: they were published or discarded.', [], 'quire-admin'));
        }

        if ($pending->contentId !== $item->id) {
            throw new NotFoundHttpException('These changes are to another item.');
        }

        return $pending;
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

        return $item;
    }

    private function toEditor(ContentTypeDefinition $type, Content $item): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate('quire_admin_content_edit', ['type' => $type->id, 'id' => $item->id]), Response::HTTP_SEE_OTHER);
    }

    private function flash(Request $request): \Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface
    {
        $session = $request->getSession();
        \assert($session instanceof FlashBagAwareSessionInterface);

        return $session->getFlashBag();
    }

    private function timezone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone($this->settings->getString('site.timezone'));
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }

    private function assertToken(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(ContentEditController::CSRF_TOKEN, $request->request->getString('_token')))) {
            throw new AccessDeniedHttpException('The form expired. Reload the page and try again.');
        }
    }
}
