<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Job;

use Quire\Jobs\Job;

/**
 * Scheduled every 5 minutes as "quire-fixture-hello.tick".
 */
final readonly class Tick implements Job
{
}
