<?php

declare(strict_types=1);

namespace Quire\Admin\Dashboard\Inbox;

use Quire\Admin\Content\ContentLists;
use Quire\Admin\Content\PendingChanges;
use Quire\Admin\Dashboard\InboxItem;
use Quire\Admin\Dashboard\InboxProviderInterface;
use Quire\Admin\Twig\RelativeTime;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\Revision\DiffLine;
use Quire\Content\Revision\PendingRevision;
use Quire\Content\Revision\PendingRevisionRepository;
use Quire\Content\Revision\ReviewState;
use Quire\Content\Revision\Verdict;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\User\CurrentUser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Changes to published items that wait for the user: others' changes to review, their own that a
 * reviewer asked them to change, and their own scheduled changes that couldn't go live.
 *
 * @internal
 */
final readonly class ChangesInbox implements InboxProviderInterface
{
    private const int LIMIT = 20;

    public function __construct(
        private PendingRevisionRepository $pending,
        private ContentRepository $content,
        private ContentTypeRegistry $types,
        private ContentLists $lists,
        private PendingChanges $changes,
        private CurrentUser $users,
        private RelativeTime $time,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public function items(): iterable
    {
        foreach ($this->pending->awaitingReview(self::LIMIT) as $pending) {
            $item = $this->item($pending);

            if ($item instanceof Content && $this->changes->mayReview($this->types->get($item->type)) && !$this->changes->isOwn($pending)) {
                yield $this->toReview($pending, $item);
            }
        }

        $user = $this->users->get();

        if (!$user instanceof \Quire\User\User) {
            return;
        }

        foreach ($this->pending->byAuthor($user->id, self::LIMIT) as $pending) {
            $item = $this->item($pending);

            if (!$item instanceof Content) {
                continue;
            }

            if (null !== $pending->publishProblem) {
                yield new InboxItem(
                    key: 'changes-not-published:'.$pending->id,
                    text: $this->translator->trans('Scheduled changes to {title} couldn’t go live at {time}', ['title' => InboxItem::about($item->title), 'time' => $pending->publishAt?->setTimezone($this->time->timezone())->format('H:i') ?? ''], 'quire-admin'),
                    url: $this->urls->generate('quire_admin_content_edit', ['type' => $item->type, 'id' => $item->id]),
                    action: $this->translator->trans('Choose', [], 'quire-admin'),
                    at: $pending->publishAt ?? $pending->updatedAt,
                    priority: 20,
                    stage: 'live',
                    tags: [['label' => $this->translator->trans('Not published', [], 'quire-admin'), 'tone' => InboxItem::TONE_DANGER]],
                    detail: $pending->publishProblem,
                );
            } elseif (ReviewState::ChangesRequested === $pending->state) {
                $request = null;

                foreach ($this->pending->reviews($pending->id) as $review) {
                    $request = Verdict::RequestChanges === $review->verdict ? $review : $request;
                }

                $name = null !== $request?->reviewerId ? $this->changes->names([$request->reviewerId])[$request->reviewerId] : null;
                yield new InboxItem(
                    key: 'changes-requested:'.$pending->id,
                    text: null === $name
                        ? $this->translator->trans('A reviewer asked for changes to {title}', ['title' => InboxItem::about($item->title)], 'quire-admin')
                        : $this->translator->trans('{name} asked for changes to {title}', ['name' => $name, 'title' => InboxItem::about($item->title)], 'quire-admin'),
                    url: $this->urls->generate('quire_admin_content_edit', ['type' => $item->type, 'id' => $item->id]),
                    action: $this->translator->trans('Open your changes', [], 'quire-admin'),
                    at: $request->createdAt ?? $pending->updatedAt,
                    priority: 15,
                    person: $name,
                    stage: 'live',
                    tags: [['label' => $this->translator->trans('changes asked for', [], 'quire-admin'), 'tone' => InboxItem::TONE_WARNING]],
                    detail: $request?->message,
                );
            }
        }
    }

    private function toReview(PendingRevision $pending, Content $item): InboxItem
    {
        $name = null !== $pending->authorId ? $this->changes->names([$pending->authorId])[$pending->authorId] : null;
        $lines = \count(array_filter($pending->diff()->bodyLines, static fn (DiffLine $line): bool => DiffLine::SAME !== $line->operation));
        $clean = $this->pending->merge($pending, $item)->isClean();

        return new InboxItem(
            key: 'review:'.$pending->id,
            text: null === $name
                ? $this->translator->trans('Changes to {title} wait for your review', ['title' => InboxItem::about($item->title)], 'quire-admin')
                : $this->translator->trans('{name} asks you to review their changes to {title}', ['name' => $name, 'title' => InboxItem::about($item->title)], 'quire-admin'),
            url: $this->urls->generate('quire_admin_content_changes', ['type' => $item->type, 'id' => $item->id, 'pending' => $pending->id]),
            action: $this->translator->trans('Review changes', [], 'quire-admin'),
            at: $pending->updatedAt,
            priority: 30,
            person: $name,
            stage: 'live',
            tags: [['label' => $this->translator->trans('changes waiting · {when}', ['when' => $this->time->text($pending->updatedAt)], 'quire-admin'), 'tone' => InboxItem::TONE_REVIEW]],
            detail: implode(' · ', [
                ...$lines > 0 ? [$this->translator->trans('{count, plural, one {# line} other {# lines}}', ['count' => $lines], 'quire-admin')] : [],
                $clean ? $this->translator->trans('merges cleanly', [], 'quire-admin') : $this->translator->trans('conflicts with what’s live', [], 'quire-admin'),
            ]),
            primary: true,
        );
    }

    /**
     * The item the changes are to, when the user may open it.
     */
    private function item(PendingRevision $pending): ?Content
    {
        $item = $this->content->find($pending->contentId);

        return $item instanceof Content && $this->types->has($item->type) && $this->lists->canList($this->types->get($item->type)) ? $item : null;
    }
}
