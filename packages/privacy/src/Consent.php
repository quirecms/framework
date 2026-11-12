<?php

declare(strict_types=1);

namespace Quire\Privacy;

use Symfony\Component\HttpFoundation\Request;

/**
 * What the visitor allowed, on the server: for what PHP does on their behalf, e.g. a server-side event
 * that must be dropped without consent. Pages are the same for every visitor (they're cached): what
 * runs in the browser is up to the consent loader, which reads the same choice.
 *
 *     if ($consent->allows($request, 'google-analytics')) { … }
 *
 * @api
 */
final readonly class Consent
{
    /** The cookie the consent loader keeps the choice in: first-party, read by the browser too */
    public const string COOKIE = 'quire_consent';

    public function __construct(
        private Services $services,
        private PrivacySettings $settings,
    ) {
    }

    /**
     * Whether the service may run for this visitor: always for a necessary one, never for an unknown
     * one, never when the browser signals a refusal (Global Privacy Control, Do Not Track) and the site
     * honours it, and otherwise only as the visitor chose, while the choice holds.
     */
    public function allows(Request $request, string $serviceId, ?\DateTimeImmutable $now = null): bool
    {
        $service = $this->services->get($serviceId);

        if (!$service instanceof Service) {
            return false;
        }

        if (!$service->category->needsConsent()) {
            return true;
        }

        if ($this->signalsRefusal($request)) {
            return false;
        }

        return $this->choice($request, $now)?->allows($serviceId) ?? false;
    }

    /**
     * Whether the visitor's browser asks not to be tracked, and the site honours it.
     */
    public function signalsRefusal(Request $request): bool
    {
        return $this->settings->honourSignals() && ('1' === $request->headers->get('Sec-GPC') || '1' === $request->headers->get('DNT'));
    }

    /**
     * The visitor's choice while it holds; null when there is none, it can't be read, or it expired.
     */
    public function choice(Request $request, ?\DateTimeImmutable $now = null): ?Choice
    {
        $value = $request->cookies->get(self::COOKIE);

        if (!\is_string($value) || '' === $value || \strlen($value) > 4096) {
            return null;
        }

        $json = base64_decode(strtr($value, '-_', '+/'), true);
        $data = false === $json ? null : json_decode($json, true);

        if (!\is_array($data) || 1 !== ($data['v'] ?? null) || !\is_string($data['id'] ?? null) || !\is_int($data['t'] ?? null) || !\is_string($data['r'] ?? null) || !\is_array($data['s'] ?? null)) {
            return null;
        }

        $services = [];

        foreach ($data['s'] as $id => $allowed) {
            if (\is_string($id) && (true === $allowed || false === $allowed || 1 === $allowed || 0 === $allowed)) {
                $services[$id] = true === $allowed || 1 === $allowed;
            }
        }

        $choice = new Choice($data['id'], new \DateTimeImmutable()->setTimestamp($data['t']), $data['r'], $services);
        $months = $choice->allowsAny() ? $this->settings->choiceMonths() : $this->settings->askAgainMonths();

        return $choice->madeAt->modify(\sprintf('+%d months', $months)) > ($now ?? new \DateTimeImmutable()) ? $choice : null;
    }
}
