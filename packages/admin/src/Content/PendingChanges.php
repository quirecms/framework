<?php

declare(strict_types=1);

namespace Quire\Admin\Content;

use Quire\Content\Action\ContentAccess;
use Quire\Content\Content;
use Quire\Content\ContentWriter;
use Quire\Content\Revision\Diff;
use Quire\Content\Revision\PendingRevision;
use Quire\Content\Revision\PendingRevisionRepository;
use Quire\Content\Revision\ReviewState;
use Quire\Content\Revision\Snapshot;
use Quire\Content\Status;
use Quire\Content\Type\ContentTypeDefinition;
use Quire\Settings\Settings;
use Quire\User\CurrentUser;
use Quire\User\UserRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Changes to published items in the admin: each editor writes their own pending revision of an item,
 * which goes live when it's published (by hand or at its time), after a review if the site asks for one.
 *
 * Writing changes takes the right to edit the item; publishing them takes "content.<type>.publish", and
 * the approval of someone with "content.<type>.review" when the site asks for approval. Reviewing takes
 * "review"; nobody approves their own changes. Their authors, and those who may publish, discard them.
 *
 * @internal
 */
final readonly class PendingChanges
{
    public function __construct(
        private PendingRevisionRepository $repository,
        private ContentAccess $access,
        private CurrentUser $users,
        private UserRepository $userRepository,
        private Settings $settings,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Whether the editor saves changes to the item as a pending revision: when visitors see it.
     */
    public function applies(Content $item): bool
    {
        return Status::PUBLISHED === $item->status && !$item->deletedAt instanceof \DateTimeImmutable;
    }

    /**
     * The current user's changes to the item, the latest when they have several.
     */
    public function own(Content $item): ?PendingRevision
    {
        $user = $this->users->get();

        return $user instanceof \Quire\User\User ? $this->repository->forContent($item->id, $user->id)[0] ?? null : (null);
    }

    /**
     * The current user's other changes to the item, older than those the editor shows: from before an
     * author could have only one set (D-41), to open or discard.
     *
     * @return list<PendingRevision>
     */
    public function earlier(Content $item): array
    {
        $user = $this->users->get();

        return $user instanceof \Quire\User\User ? \array_slice($this->repository->forContent($item->id, $user->id), 1) : [];
    }

    /**
     * Whether the item's text or fields were published anew since the changes started: they're kept
     * when the changes are published, merged with them.
     */
    public function liveChanged(Content $item, PendingRevision $pending): bool
    {
        return $item->version !== $pending->baseVersion && [] !== Diff::between($pending->base, Snapshot::of($item))->changes;
    }

    /**
     * Everyone else's changes to the item.
     *
     * @return list<PendingRevision>
     */
    public function others(Content $item): array
    {
        $user = $this->users->get()?->id;

        return array_values(array_filter($this->repository->forContent($item->id), static fn (PendingRevision $pending): bool => null === $user || $pending->authorId !== $user));
    }

    public function isOwn(PendingRevision $pending): bool
    {
        return null !== $pending->authorId && $pending->authorId === $this->users->get()?->id;
    }

    public function approvalRequired(): bool
    {
        return $this->settings->getBool(ContentWriter::APPROVAL_SETTING);
    }

    /**
     * Whether they may publish changes to the item (D-42: the item is passed, so rules decide on it).
     */
    public function mayPublish(Content $item): bool
    {
        return $this->access->mayPublish($item);
    }

    /**
     * Whether saving the item straight away, rather than as changes, is theirs: it changes what visitors
     * see, or will see, once it's published or scheduled, so that takes the right to publish. The same
     * for the editor's save and the history's restore (M-02).
     */
    public function maySave(Content $item): bool
    {
        return !\in_array($item->status, [Status::PUBLISHED, Status::SCHEDULED], true) || $this->mayPublish($item);
    }

    /**
     * For a new item, before it exists: what roles and rules say for the type.
     */
    public function mayPublishType(ContentTypeDefinition $type): bool
    {
        return $this->access->may($type->id, 'publish');
    }

    /**
     * Whether the user may publish these changes now, or why not.
     *
     * @return true|string
     */
    public function canPublish(Content $item, PendingRevision $pending): bool|string
    {
        return match (true) {
            !$this->mayPublish($item) => $this->translator->trans('Publishing takes the right to publish; ask someone who has it.', [], 'quire-admin'),
            $this->approvalRequired() && ReviewState::Approved !== $pending->state => $this->translator->trans('The site asks for changes to be approved by a reviewer before they\'re published.', [], 'quire-admin'),
            default => true,
        };
    }

    public function mayReview(Content $item): bool
    {
        return $this->access->mayReview($item);
    }

    public function mayApprove(Content $item, PendingRevision $pending): bool
    {
        return $this->mayReview($item) && !$this->isOwn($pending);
    }

    public function mayDiscard(Content $item, PendingRevision $pending): bool
    {
        return $this->isOwn($pending) || $this->mayPublish($item);
    }

    public function stateLabel(ReviewState $state): string
    {
        return $this->translator->trans('changes.'.$state->value, [], 'quire-admin');
    }

    /**
     * Users' names, for showing who wrote, reviewed or scheduled what.
     *
     * @param list<string|null> $ids
     *
     * @return array<string, string> Names, by ID
     */
    public function names(array $ids): array
    {
        $names = [];
        $users = $this->userRepository->findMany(array_filter($ids, \is_string(...)));

        foreach (array_unique(array_filter($ids, \is_string(...))) as $id) {
            $names[$id] = ($users[$id] ?? null)->displayName ?? $this->translator->trans('a former user', [], 'quire-admin');
        }

        return $names;
    }
}
