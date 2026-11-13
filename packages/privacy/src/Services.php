<?php

declare(strict_types=1);

namespace Quire\Privacy;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The services the site's enabled extensions and theme declared ({@see Extend\Privacy}), in the order
 * they were declared, as they are for this request: a service with a switch runs only while its switch
 * turns it on, with what the switch fills in.
 *
 * @api
 */
final class Services implements ResetInterface
{
    /** @internal Parameter: the declarations of every enabled extension and theme */
    public const string PARAMETER = 'quire-privacy.services';

    /** @var array<string, Service> Every declared service, as declared */
    private readonly array $declared;

    /** @var array<string, array{switched: bool, runtime: list<string>, path: string}> */
    private array $declarations = [];

    /** @var array<string, ServiceSwitchInterface> The switch of each switched service, if it has a valid one */
    private array $switches = [];

    /** @var array<string, Service>|null This request's */
    private ?array $services = null;

    /**
     * @param list<array{id: string, label: string, category: string, vendor: string, purpose: string, cookies: list<array{string, string, string}>, hosts: array<string, list<string>>, retention: ?string, policy_url: ?string, script: ?string, config: array<string, scalar>, owner: string, switched?: bool, runtime?: list<string>, path?: string}> $declarations
     * @param iterable<ServiceSwitchInterface>                                                                                                                                                                                                                                                                                                            $switches
     */
    public function __construct(
        #[Autowire(param: self::PARAMETER)]
        array $declarations = [],
        #[AutowireIterator(ServiceSwitchInterface::TAG)]
        iterable $switches = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
        $services = [];

        foreach ($declarations as $declaration) {
            if (isset($services[$declaration['id']])) {
                throw new \LogicException(\sprintf('The service "%s" is declared by "%s" and by "%s": a service is declared once, by the extension or theme that loads it.', $declaration['id'], $services[$declaration['id']]->owner, $declaration['owner']));
            }

            $services[$declaration['id']] = new Service(
                $declaration['id'],
                $declaration['label'],
                Category::from($declaration['category']),
                $declaration['vendor'],
                $declaration['purpose'],
                array_map(static fn (array $cookie): Cookie => new Cookie(...$cookie), $declaration['cookies']),
                $declaration['hosts'],
                $declaration['retention'],
                $declaration['policy_url'],
                $declaration['script'],
                $declaration['config'],
                $declaration['owner'],
            );
            $this->declarations[$declaration['id']] = ['switched' => $declaration['switched'] ?? false, 'runtime' => $declaration['runtime'] ?? [], 'path' => $declaration['path'] ?? ''];
        }

        $this->declared = $services;

        foreach ($switches as $switch) {
            $problem = self::switchProblem($switch::class, $this->declarations, $this->switches);

            if (null === $problem) {
                $this->switches[$switch::service()] = $switch;
            } else {
                // The build refuses it (ServiceSwitchesPass); if one got past it, it switches nothing.
                $this->logger?->warning('{problem} It\'s ignored.', ['problem' => $problem]);
            }
        }
    }

    /**
     * Why a switch may not switch its service, or null when it may: the service is declared, with a
     * switch, by the package the switch's class is from, and has no other switch.
     *
     * @internal For ServiceSwitchesPass
     *
     * @param class-string<ServiceSwitchInterface>                                      $class
     * @param array<string, array{switched: bool, runtime: list<string>, path: string}> $declarations
     * @param array<string, mixed>                                                      $taken
     */
    public static function switchProblem(string $class, array $declarations, array $taken): ?string
    {
        $id = $class::service();
        $declaration = $declarations[$id] ?? null;

        if (null === $declaration || !$declaration['switched']) {
            return \sprintf('"%s" switches the service "%s", which isn\'t declared with a switch.', $class, $id);
        }

        if (isset($taken[$id])) {
            return \sprintf('"%s" switches the service "%s", which has a switch already: a service has one.', $class, $id);
        }

        $file = new \ReflectionClass($class)->getFileName();
        $real = \is_string($file) ? realpath($file) : false;
        $package = '' === $declaration['path'] ? false : realpath($declaration['path']);

        if (false === $real || false === $package || !str_starts_with($real, $package.\DIRECTORY_SEPARATOR)) {
            return \sprintf('"%s" switches the service "%s", which another package declared: only the package that declares a service may switch it.', $class, $id);
        }

        return null;
    }

    public function reset(): void
    {
        $this->services = null;
    }

    /**
     * Every declared service, whether or not its switch turns it on now: what can run on the site, e.g.
     * for checking that templates don't load one before consent.
     *
     * @return list<Service>
     */
    public function declared(): array
    {
        return array_values($this->declared);
    }

    /**
     * @return list<Service>
     */
    public function all(): array
    {
        return array_values($this->services());
    }

    public function get(string $id): ?Service
    {
        return $this->services()[$id] ?? null;
    }

    /**
     * The services visitors are asked about: all but the necessary ones.
     *
     * @return list<Service>
     */
    public function askedAbout(): array
    {
        return array_values(array_filter($this->services(), static fn (Service $service): bool => $service->category->needsConsent()));
    }

    /**
     * Whether visitors are asked anything: without such a service, there is no banner at all.
     */
    public function needConsent(): bool
    {
        return [] !== $this->askedAbout();
    }

    /**
     * The categories visitors choose from, in their order: only those a service uses.
     *
     * @return list<Category>
     */
    public function categories(): array
    {
        $used = array_map(static fn (Service $service): Category => $service->category, $this->askedAbout());

        return array_values(array_filter(Category::cases(), static fn (Category $category): bool => \in_array($category, $used, true)));
    }

    /**
     * Changes when what visitors are asked about changes, i.e. a service is added, or one asks for
     * another category, host or cookie. Visitors are then asked again, about what's new only.
     */
    public function revision(): string
    {
        return substr(hash('sha256', json_encode(array_map(static fn (Service $service): array => [$service->id, $service->revision()], $this->askedAbout()), \JSON_THROW_ON_ERROR)), 0, 12);
    }

    /**
     * Other sites' hosts of every service, by directive, for the site's Content-Security-Policy.
     *
     * @return array<string, list<string>>
     */
    public function hosts(): array
    {
        $hosts = [];

        foreach ($this->services() as $service) {
            foreach ($service->hosts as $directive => $sources) {
                $hosts[$directive] = array_values(array_unique([...$hosts[$directive] ?? [], ...$sources]));
            }
        }

        return $hosts;
    }

    /**
     * This request's services: those without a switch, and those their switch turns on, with what it
     * fills in. A switch that fails, or fills in what its declaration doesn't name, leaves its service
     * off: never on with values it can't vouch for.
     *
     * @return array<string, Service>
     */
    private function services(): array
    {
        if (null !== $this->services) {
            return $this->services;
        }

        $services = [];

        foreach ($this->declared as $id => $service) {
            if (!$this->declarations[$id]['switched']) {
                $services[$id] = $service;

                continue;
            }

            $switch = $this->switches[$id] ?? null;

            if (null === $switch) {
                continue;
            }

            try {
                if (!$switch->enabled()) {
                    continue;
                }

                $config = $switch->config();
            } catch (\Throwable $e) {
                $this->logger?->warning('The service {service} is off for this request: its switch failed ({message}).', ['service' => $id, 'message' => $e->getMessage(), 'exception' => $e]);

                continue;
            }

            $unknown = array_diff(array_keys($config), $this->declarations[$id]['runtime']);
            $notScalar = array_filter($config, static fn (mixed $value): bool => !\is_scalar($value));

            if ([] !== $unknown || [] !== $notScalar) {
                $this->logger?->warning('The service {service} is off for this request: its switch gave {keys}, which its declaration doesn\'t name as runtime keys or which aren\'t scalars.', ['service' => $id, 'keys' => implode(', ', [...$unknown, ...array_keys($notScalar)])]);

                continue;
            }

            $runtime = [];

            foreach ($config as $key => $value) {
                if (\is_scalar($value)) {
                    $runtime[$key] = $value;
                }
            }

            $services[$id] = new Service($service->id, $service->label, $service->category, $service->vendor, $service->purpose, $service->cookies, $service->hosts, $service->retention, $service->policyUrl, $service->script, [...$service->config, ...$runtime], $service->owner);
        }

        return $this->services = $services;
    }
}
