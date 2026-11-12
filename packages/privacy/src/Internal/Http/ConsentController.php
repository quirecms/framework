<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal\Http;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
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
 * is now, and a choice, or an address, may be logged only so many times an hour.
 *
 * @internal
 */
final readonly class ConsentController
{
    public const string ROUTE = 'quire_privacy_consent';

    private const int MAX_BODY = 8192;

    /**
     * Choices an hour. A visitor saves a few; per choice ID, that's most of what the log can be
     * flooded with from one browser. Per address it's far more, because many visitors can share one
     * (a mobile carrier's, an office's): 300 is five a minute, all day, from one address.
     */
    public const int PER_CHOICE = 10;
    public const int PER_ADDRESS = 300;

    private RateLimiterFactory $perChoice;
    private RateLimiterFactory $perAddress;
    private RateLimiterFactory $warnings;

    public function __construct(
        private ConsentLog $log,
        #[Autowire(service: 'cache.app')]
        CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
    ) {
        $storage = new CacheStorage($cache);
        $this->perChoice = new RateLimiterFactory(['id' => 'quire_privacy_consent_choice', 'policy' => 'sliding_window', 'limit' => self::PER_CHOICE, 'interval' => '1 hour'], $storage);
        $this->perAddress = new RateLimiterFactory(['id' => 'quire_privacy_consent_address', 'policy' => 'sliding_window', 'limit' => self::PER_ADDRESS, 'interval' => '1 hour'], $storage);
        $this->warnings = new RateLimiterFactory(['id' => 'quire_privacy_consent_warning', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '10 minutes'], $storage);
    }

    public function __invoke(Request $request): Response
    {
        if (!$this->fromTheSite($request)) {
            return new JsonResponse(['error' => 'Only the site\'s own pages log choices.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->perAddress->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->tooMany('address', self::PER_ADDRESS);
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

        if (1 === preg_match('/^[0-9a-f]{32}$/', $data['id']) && !$this->perChoice->create($data['id'])->consume()->isAccepted()) {
            return $this->tooMany('choice', self::PER_CHOICE);
        }

        try {
            $this->log->record($data['id'], $answers);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Refused, and not logged as proof: the owner hears of it (at most every ten minutes, and never
     * the address, as the site's other logs).
     */
    private function tooMany(string $per, int $limit): JsonResponse
    {
        if ($this->warnings->create($per)->consume()->isAccepted()) {
            $this->logger->warning('Consent choices weren\'t logged as proof: more than {limit} an hour per {per}. On a site behind a proxy, make sure the proxy is trusted (SYMFONY_TRUSTED_PROXIES), or every visitor shares its address.', ['route' => self::ROUTE, 'per' => $per, 'limit' => $limit]);
        }

        return new JsonResponse(['error' => 'Too many choices. Try again later.'], Response::HTTP_TOO_MANY_REQUESTS);
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
