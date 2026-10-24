<?php

declare(strict_types=1);

namespace Quire\Admin\Content;

use Quire\Content\Content;
use Quire\Content\ContentWriter;
use Quire\Content\Revision\PendingRevision;
use Quire\Content\Revision\PendingRevisionRepository;
use Quire\Content\Revision\ReviewState;
use Quire\Content\Status;
use Quire\Content\Type\ContentTypeDefinition;
use Quire\Settings\Settings;
use Quire\User\CurrentUser;
use Quire\User\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
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
        private Security $security,
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

    public function mayPublish(ContentTypeDefinition $type): bool
    {
        return $this->security->isGranted(ContentLists::ability($type, 'publish'));
    }

    /**
     * Whether the user may publish these changes now, or why not.
     *
     * @return true|string
     */
    public function canPublish(ContentTypeDefinition $type, PendingRevision $pending): bool|string
    {
        return match (true) {
            !$this->mayPublish($type) => $this->translator->trans('Publishing takes the right to publish; ask someone who has it.', [], 'quire-admin'),
            $this->approvalRequired() && ReviewState::Approved !== $pending->state => $this->translator->trans('The site asks for changes to be approved by a reviewer before they\'re published.', [], 'quire-admin'),
            default => true,
        };
    }

    public function mayReview(ContentTypeDefinition $type): bool
    {
        return $this->security->isGranted(ContentLists::ability($type, 'review'));
    }

    public function mayApprove(ContentTypeDefinition $type, PendingRevision $pending): bool
    {
        return $this->mayReview($type) && !$this->isOwn($pending);
    }

    public function mayDiscard(ContentTypeDefinition $type, PendingRevision $pending): bool
    {
        return $this->isOwn($pending) || $this->mayPublish($type);
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

        foreach (array_unique(array_filter($ids, \is_string(...))) as $id) {
            $user = $this->userRepository->find($id);
            $names[$id] = $user->displayName ?? $this->translator->trans('a former user', [], 'quire-admin');
        }

        return $names;
    }
}
