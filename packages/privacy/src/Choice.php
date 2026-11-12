<?php

declare(strict_types=1);

namespace Quire\Privacy;

/**
 * A visitor's choice, as their browser keeps it (the "quire_consent" cookie, written by the consent
 * loader): which services they allowed, when, and for which revision of the site's services.
 *
 * @api
 */
final readonly class Choice
{
    /**
     * @param string                      $id       A random ID of this choice, the same in the consent log;
     *                                              never the visitor's account
     * @param array<string, string|false> $services By service ID: the service's revision the visitor
     *                                              allowed, or false for a refusal; missing when it wasn't
     *                                              asked about yet (it was added since)
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $madeAt,
        public string $revision,
        public array $services,
    ) {
    }

    /**
     * Whether the visitor allowed the service as it is now: a "yes" to an earlier revision (before it
     * reached another host, or kept another cookie) is no answer yet.
     */
    public function allows(string $serviceId, string $revision): bool
    {
        return $revision === ($this->services[$serviceId] ?? false);
    }

    /** Whether it allows any service at all */
    public function allowsAny(): bool
    {
        return [] !== array_filter($this->services, \is_string(...));
    }
}
