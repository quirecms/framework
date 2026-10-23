<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Job;

use Quire\Jobs\Job;

final readonly class SendGreeting implements Job
{
    public function __construct(
        public string $name,
    ) {
    }
}
