<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Quire\Extension\Rebuild\AppRebuilderInterface;
use Quire\Extension\Rebuild\RebuildFailedException;

/**
 * Records rebuilds instead of running them: a real one runs console commands in new processes, which
 * would rebuild the test application while it runs.
 */
final class RecordingAppRebuilder implements AppRebuilderInterface
{
    /** @var list<bool> Whether each rebuild was asked to run migrations */
    public array $calls = [];

    public function __construct(
        private readonly ?string $failWith = null,
    ) {
    }

    public function rebuild(bool $migrate, \Closure $output): void
    {
        $this->calls[] = $migrate;

        if (null !== $this->failWith && $migrate) {
            throw new RebuildFailedException($this->failWith);
        }
    }
}
