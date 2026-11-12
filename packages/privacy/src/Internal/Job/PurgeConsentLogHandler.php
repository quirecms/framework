<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal\Job;

use Quire\Privacy\ConsentLog;
use Quire\Privacy\PrivacySettings;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @internal
 */
#[AsMessageHandler]
final readonly class PurgeConsentLogHandler
{
    public function __construct(private ConsentLog $log, private PrivacySettings $settings)
    {
    }

    public function __invoke(PurgeConsentLog $job): void
    {
        $this->log->purge($this->settings->proofCutoff(new \DateTimeImmutable()));
    }
}
