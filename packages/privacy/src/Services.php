<?php

declare(strict_types=1);

namespace Quire\Privacy;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The services the site's enabled extensions and theme declared ({@see Extend\Privacy}), in the order
 * they were declared.
 *
 * @api
 */
final readonly class Services
{
    /** @internal Parameter: the declarations of every enabled extension and theme */
    public const string PARAMETER = 'quire-privacy.services';

    /** @var array<string, Service> */
    private array $services;

    /**
     * @param list<array{id: string, label: string, category: string, vendor: string, purpose: string, cookies: list<array{string, string, string}>, hosts: array<string, list<string>>, retention: ?string, policy_url: ?string, script: ?string, config: array<string, scalar>, owner: string}> $declarations
     */
    public function __construct(
        #[Autowire(param: self::PARAMETER)]
        array $declarations = [],
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
        }

        $this->services = $services;
    }

    /**
     * @return list<Service>
     */
    public function all(): array
    {
        return array_values($this->services);
    }

    public function get(string $id): ?Service
    {
        return $this->services[$id] ?? null;
    }

    /**
     * The services visitors are asked about: all but the necessary ones.
     *
     * @return list<Service>
     */
    public function askedAbout(): array
    {
        return array_values(array_filter($this->services, static fn (Service $service): bool => $service->category->needsConsent()));
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

        foreach ($this->services as $service) {
            foreach ($service->hosts as $directive => $sources) {
                $hosts[$directive] = array_values(array_unique([...$hosts[$directive] ?? [], ...$sources]));
            }
        }

        return $hosts;
    }
}
