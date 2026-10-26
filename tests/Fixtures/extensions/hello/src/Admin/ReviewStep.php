<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Admin;

use Quire\Admin\Content\NextStep;
use Quire\Admin\Content\NextStepInterface;
use Quire\Content\Content;
use Quire\Content\Status;

/**
 * Sad drafts go to review, through the fixture's own transition, before anyone publishes them.
 */
final readonly class ReviewStep implements NextStepInterface
{
    public function nextStep(Content $item): ?NextStep
    {
        return Status::DRAFT === $item->status && 'sad' === ($item->fields['mood'] ?? null) ? new NextStep('Send for review', action: 'transition:submit', primary: true) : null;
    }
}
