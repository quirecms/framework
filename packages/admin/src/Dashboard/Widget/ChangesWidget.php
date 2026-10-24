<?php

declare(strict_types=1);

namespace Quire\Admin\Dashboard\Widget;

use Quire\Admin\Content\ContentLists;
use Quire\Admin\Content\PendingChanges;
use Quire\Admin\Dashboard\WidgetInterface;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\Revision\PendingRevision;
use Quire\Content\Revision\PendingRevisionRepository;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Settings\Settings;
use Quire\User\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Changes to published items: those waiting for the user's review, and the user's own, e.g. ones that
 * couldn't be published at their time.
 *
 * @internal
 */
final readonly class ChangesWidget implements WidgetInterface
{
    private const int LIMIT = 10;

    public function __construct(
        private PendingRevisionRepository $pending,
        private ContentRepository $content,
        private ContentTypeRegistry $types,
        private ContentLists $lists,
        private PendingChanges $changes,
        private CurrentUser $users,
        private Settings $settings,
        private Environment $twig,
        private TranslatorInterface $translator,
    ) {
    }

    public function title(): string
    {
        return $this->translator->trans('Changes', [], 'quire-admin');
    }

    public function render(): string
    {
        $user = $this->users->get();
        $toReview = $this->entries($this->pending->awaitingReview(self::LIMIT), review: true);
        $own = $user instanceof \Quire\User\User ? $this->entries($this->pending->byAuthor($user->id, self::LIMIT), review: false) : [];

        if ([] === $toReview && [] === $own) {
            return '';
        }

        return $this->twig->render('@quire_admin/dashboard/changes.html.twig', [
            'to_review' => $toReview,
            'own' => $own,
            'names' => $this->changes->names(array_map(static fn (array $entry): ?string => $entry['pending']->authorId, $toReview)),
            'timezone' => $this->settings->getString('site.timezone'),
        ]);
    }

    /**
     * @param list<PendingRevision> $revisions
     *
     * @return list<array{pending: PendingRevision, item: Content, state: string}>
     */
    private function entries(array $revisions, bool $review): array
    {
        $entries = [];

        foreach ($revisions as $pending) {
            $item = $this->content->find($pending->contentId);

            if (!$item instanceof Content || !$this->types->has($item->type)) {
                continue;
            }

            $type = $this->types->get($item->type);

            // Only what the user may open, and for review only what they may review (not their own).
            if (!$this->lists->canList($type) || ($review && (!$this->changes->mayReview($type) || $this->changes->isOwn($pending)))) {
                continue;
            }

            $entries[] = ['pending' => $pending, 'item' => $item, 'state' => $this->changes->stateLabel($pending->state)];
        }

        return $entries;
    }
}
