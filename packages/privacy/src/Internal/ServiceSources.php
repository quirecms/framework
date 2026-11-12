<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal;

use Quire\Csp\ThirdPartySourcesInterface;
use Quire\Privacy\Services;

/**
 * Fills core's one slot for other sites' hosts in the Content-Security-Policy (doc 20, condition C-C)
 * with those of every declared service. The policy is an allowlist, the same for every visitor: a host
 * in it may load, but a service's script runs only once the visitor allows it (the consent loader).
 * Core keeps them off pages with forms for passwords, consent or not.
 *
 * @internal
 */
final readonly class ServiceSources implements ThirdPartySourcesInterface
{
    public function __construct(private Services $services)
    {
    }

    public function sources(): array
    {
        return $this->services->hosts();
    }
}
