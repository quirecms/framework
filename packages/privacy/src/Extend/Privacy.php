<?php

declare(strict_types=1);

namespace Quire\Privacy\Extend;

use Quire\Extend\ExtenderContext;
use Quire\Extend\ExtenderInterface;
use Quire\Privacy\Category;
use Quire\Privacy\Cookie;
use Quire\Privacy\Service;
use Quire\Privacy\Services;

/**
 * Declares the services of an extension or theme that visitors may be asked about, in its extend.php:
 *
 *     new Privacy()->service(
 *         'acme-video',
 *         label: 'Acme Video',
 *         category: Category::Marketing,
 *         vendor: 'Acme Video Ltd',
 *         purpose: 'Plays the videos in posts.',
 *         cookies: [new Cookie('acme_id', '1 year', 'Recognises you across sites.')],
 *         hosts: ['frame-src' => ['https://player.acme-video.example']],
 *         policyUrl: 'https://acme-video.example/privacy',
 *     ),
 *
 * A service's hosts are allowed by the site's Content-Security-Policy while it's declared: a host no
 * service declares can't load. What runs only after consent is the service's script, which the
 * consent loader starts once the visitor allows it. Require "quire-privacy" in the extension's
 * manifest ("requires"), or declare the services only when it's enabled
 * ({@see \Quire\Extend\Conditional::whenExtensionEnabled()}).
 *
 * @api
 */
final class Privacy implements ExtenderInterface
{
    /** @var list<array{id: string, label: string, category: string, vendor: string, purpose: string, cookies: list<array{string, string, string}>, hosts: array<string, list<string>>, retention: ?string, policy_url: ?string, script: ?string, config: array<string, scalar>, switched: bool, runtime: list<string>}> */
    private array $services = [];

    /**
     * @param list<Cookie>                $cookies
     * @param array<string, list<string>> $hosts
     * @param array<string, scalar>       $config
     * @param bool                        $switched Off unless its package's switch turns it on while the site
     *                                              runs (first-party only, see ServiceSwitchInterface)
     * @param list<string>                $runtime  Config keys the switch fills, e.g. ["measurement_id"]
     *
     * @see Service for each other argument
     */
    public function service(
        string $id,
        string $label,
        Category $category,
        string $vendor,
        string $purpose,
        array $cookies = [],
        array $hosts = [],
        ?string $retention = null,
        ?string $policyUrl = null,
        ?string $script = null,
        array $config = [],
        bool $switched = false,
        array $runtime = [],
    ): self {
        // Checked now, so a mistake names the extension that made it.
        new Service($id, $label, $category, $vendor, $purpose, $cookies, $hosts, $retention, $policyUrl, $script, $config);

        if ([] !== $runtime && !$switched) {
            throw new \InvalidArgumentException(\sprintf('The service "%s" has runtime keys but no switch: only a switch fills them (switched: true).', $id));
        }

        foreach ($runtime as $key) {
            if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $key) || \array_key_exists($key, $config)) {
                throw new \InvalidArgumentException(\sprintf('The service "%s" names "%s" as a runtime key: a runtime key is a name like "measurement_id", and not one of its config\'s, which the switch could then replace.', $id, $key));
            }
        }

        $this->services[] = [
            'id' => $id,
            'label' => $label,
            'category' => $category->value,
            'vendor' => $vendor,
            'purpose' => $purpose,
            'cookies' => array_map(static fn (Cookie $cookie): array => [$cookie->name, $cookie->lifetime, $cookie->purpose], $cookies),
            'hosts' => $hosts,
            'retention' => $retention,
            'policy_url' => $policyUrl,
            'script' => $script,
            'config' => $config,
            'switched' => $switched,
            'runtime' => $runtime,
        ];

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        foreach ($this->services as $service) {
            $context->appendToParameter(Services::PARAMETER, [...$service, 'owner' => $context->id, 'path' => $context->path]);
        }
    }
}
