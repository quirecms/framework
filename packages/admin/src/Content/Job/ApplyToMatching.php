<?php

declare(strict_types=1);

namespace Quire\Admin\Content\Job;

use Quire\Jobs\Job;

/**
 * Takes a content list's action on every item its filters find, in the background, as the user who
 * chose it: for "Select all … that match" when there are more than a request should do.
 *
 * @internal
 */
final readonly class ApplyToMatching implements Job
{
    /**
     * @param array{types: non-empty-list<string>, template?: bool, calls?: list<array{0: string, 1: list<mixed>}>} $query What the list found (see ContentQuery::toArray())
     */
    public function __construct(
        public string $type,
        public string $action,
        public array $query,
        public string $userId,
    ) {
    }
}
