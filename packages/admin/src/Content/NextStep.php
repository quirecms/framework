<?php

declare(strict_types=1);

namespace Quire\Admin\Content;

/**
 * What to do next with an item, shown in its row of the content lists: a link, or one of the list's
 * actions (see {@see ContentLists::actions()}), e.g. "transition:publish".
 *
 * @api
 */
final readonly class NextStep
{
    /**
     * @param string      $label   What to do, translated, e.g. "Review changes"
     * @param string|null $url     Where to do it
     * @param string|null $action  Or the list's action that does it at once
     * @param bool        $primary Whether it's the main thing to do (a filled button), not a suggestion
     */
    public function __construct(
        public string $label,
        public ?string $url = null,
        public ?string $action = null,
        public bool $primary = false,
    ) {
        if ((null === $url) === (null === $action)) {
            throw new \InvalidArgumentException('A next step has a URL or an action.');
        }
    }
}
