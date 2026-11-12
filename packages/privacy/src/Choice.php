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
     * @param string              $id       A random ID of this choice, the same in the consent log;
     *                                      never the visitor's account
     * @param array<string, bool> $services By service ID; a service missing from it wasn't asked about
     *                                      yet (it was added since)
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $madeAt,
        public string $revision,
        public array $services,
    ) {
    }

    public function allows(string $serviceId): bool
    {
        return true === ($this->services[$serviceId] ?? false);
    }

    /** Whether it allows any service at all */
    public function allowsAny(): bool
    {
        return \in_array(true, $this->services, true);
    }
}
