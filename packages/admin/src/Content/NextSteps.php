<?php

declare(strict_types=1);

namespace Quire\Admin\Content;

use Quire\Content\Check\CheckReport;
use Quire\Content\Content;
use Quire\Content\Revision\ReviewState;
use Quire\Content\Status;
use Quire\Content\Type\ContentTypeDefinition;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The next step for each item of a content list, for the signed-in user, first that applies:
 *
 * 1. changes waiting for their review: "Review changes";
 * 2. their own changes sent back: "See what to change";
 * 3. findings of the checks: "Fix it", "Fix 2 things";
 * 4. an extension's step (see {@see NextStepInterface});
 * 5. a draft they may publish: "Publish".
 *
 * Rows read what they need from attributes the list's query selects (see {@see ContentLists::page()}).
 *
 * @internal
 */
final readonly class NextSteps
{
    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, string>   $actions    What the user may do with the item now, by list action
     * @param list<NextStepInterface> $extensions The extensions' steps for the type, in order
     * @param bool                    $mayEdit    Whether the user may edit the item
     */
    public function for(ContentTypeDefinition $type, Content $item, CheckReport $report, array $actions, array $extensions, bool $mayEdit): ?NextStep
    {
        if ($item->isTrashed()) {
            return null;
        }

        $edit = $this->urls->generate('quire_admin_content_edit', ['type' => $type->id, 'id' => $item->id]);
        $review = $item->attributes['review_pending_id'] ?? null;

        if (\is_string($review) && $this->security->isGranted(ContentLists::ability($type, 'review'))) {
            return new NextStep($this->translator->trans('Review changes', [], 'quire-admin'), $this->urls->generate('quire_admin_content_changes', ['type' => $type->id, 'id' => $item->id, 'pending' => $review]), primary: true);
        }

        if (ReviewState::ChangesRequested->value === ($item->attributes['own_pending_state'] ?? null)) {
            return new NextStep($this->translator->trans('See what to change', [], 'quire-admin'), $edit, primary: true);
        }

        $findings = \count($report->results);

        if ($findings > 0 && $mayEdit) {
            return new NextStep($this->translator->trans('{count, plural, one {Fix it} other {Fix # things}}', ['count' => $findings], 'quire-admin'), $edit.'#checks');
        }

        foreach ($extensions as $extension) {
            $step = $extension->nextStep($item);

            if ($step instanceof NextStep) {
                return $step;
            }
        }

        if (Status::DRAFT === $item->status && isset($actions['transition:'.Status::PUBLISH])) {
            return new NextStep($actions['transition:'.Status::PUBLISH], action: 'transition:'.Status::PUBLISH, primary: true);
        }

        return null;
    }
}
