<?php

declare(strict_types=1);

namespace Quire\Admin\Content;

use Quire\Content\Content;

/**
 * A next step of the admin's content lists, added with {@see \Quire\Admin\Extend\Admin::contentNextStep()},
 * e.g. for a transition an extension adds to the workflow ("Send to translators").
 *
 * Lists ask for it after the admin's own steps that need someone (changes to review, findings to fix)
 * and before "Publish". The first step returned is shown.
 *
 * @api
 */
interface NextStepInterface
{
    /**
     * The next step for the item, for the signed-in user; null when there's none.
     */
    public function nextStep(Content $item): ?NextStep;
}
