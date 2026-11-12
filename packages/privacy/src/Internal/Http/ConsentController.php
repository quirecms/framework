<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal\Http;

use Psr\Cache\CacheItemPoolInterface;
use Quire\Privacy\ConsentLog;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Where the consent loader logs a visitor's choice (POST /_quire/privacy/consent), as proof.
 *
 * Pages are the same for every visitor (cached), so there's no form token to check: the request must
 * come from the site's own pages (Fetch Metadata, or Origin), each answer must be about a service as it
 * is now, and an address may log only so many choices an hour.
 *
 * @internal
 */
final readonly class ConsentController
{
    public const string ROUTE = 'quire_privacy_consent';

    private const int MAX_BODY = 8192;

    private RateLimiterFactory $limits;

    public function __construct(
        private ConsentLog $log,
        #[Autowire(service: 'cache.app')]
        CacheItemPoolInterface $cache,
    ) {
        $this->limits = new RateLimiterFactory(['id' => 'quire_privacy_consent', 'policy' => 'sliding_window', 'limit' => 30, 'interval' => '1 hour'], new CacheStorage($cache));
    }

    public function __invoke(Request $request): Response
    {
        if (!$this->fromTheSite($request)) {
            return new JsonResponse(['error' => 'Only the site\'s own pages log choices.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->limits->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Too many choices from this address. Try again later.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // A choice is small: a longer body isn't read.
        if ((int) $request->headers->get('Content-Length', '0') > self::MAX_BODY) {
            return new JsonResponse(['error' => 'A choice is small.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $body = $request->getContent();

        if (\strlen($body) > self::MAX_BODY) {
            return new JsonResponse(['error' => 'A choice is small.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $data = json_decode($body, true, 4);

        if (!\is_array($data) || !\is_string($data['id'] ?? null) || !\is_array($data['s'] ?? null)) {
            return new JsonResponse(['error' => 'A choice is {"id": "…", "s": {"<service>": "<revision>" or false}}.'], Response::HTTP_BAD_REQUEST);
        }

        $answers = [];

        foreach ($data['s'] as $service => $answer) {
            if (!\is_string($service) || false !== $answer && !\is_string($answer)) {
                return new JsonResponse(['error' => 'An answer is a service\'s revision, or false.'], Response::HTTP_BAD_REQUEST);
            }

            $answers[$service] = $answer;
        }

        try {
            $this->log->record($data['id'], $answers);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * The browser says the request comes from the site's own pages: Fetch Metadata where it sends it,
     * otherwise the Origin. Neither, and it isn't taken.
     */
    private function fromTheSite(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');

        if (null !== $site) {
            return 'same-origin' === $site;
        }

        $origin = $request->headers->get('Origin');

        return null !== $origin && $origin === $request->getSchemeAndHttpHost();
    }
}
