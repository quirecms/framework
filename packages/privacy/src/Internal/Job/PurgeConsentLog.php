<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal\Job;

use Quire\Jobs\Job;

/**
 * Removes logged choices older than "quire-privacy.log_months" (daily).
 *
 * @internal
 */
final readonly class PurgeConsentLog implements Job
{
}
