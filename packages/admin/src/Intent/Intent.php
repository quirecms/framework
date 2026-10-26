<?php

declare(strict_types=1);

namespace Quire\Admin\Intent;

/**
 * What someone asked the intent bar to do, understood: an action of the catalogue with its arguments,
 * shown as its parts, what will happen, and the command that does the same.
 *
 * @internal
 */
final readonly class Intent
{
    /**
     * @param array<string, mixed>                     $arguments
     * @param list<array{label: string, kind: string}> $parts     What was understood: the verb, the item, the time…
     * @param list<string>                             $steps     What will happen, in order
     * @param string|null                              $denied    Why the person can't, or null
     * @param string|null                              $change    Where to change it by hand, e.g. the item's editor
     */
    public function __construct(
        public string $action,
        public array $arguments,
        public array $parts,
        public array $steps,
        public ?string $command,
        public ?string $denied,
        public ?string $change,
    ) {
    }
}
