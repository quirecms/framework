<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal\Lint;

use Quire\Privacy\Services;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * Refuses to build the site while a template it would render (the site's own, its theme's, an enabled
 * extension's) loads a service visitors are asked about directly: that load would happen before they
 * consent. So a theme with such a load isn't taken into use (the switch rolls back and says why), and
 * a deploy that adds one fails, as a mistake in a service's declaration does.
 *
 * Runs once the template directories are set (Quire's TemplatePathsPass). While developing, templates
 * changed after the site was built are checked by "quire:theme:lint" and the admin's themes page.
 *
 * @internal
 */
final readonly class ConsentLoadsPass implements CompilerPassInterface
{
    private const string LOADER = 'twig.loader.native_filesystem';

    public function process(ContainerBuilder $container): void
    {
        /** @var list<array{id: string, label: string, category: string, vendor: string, purpose: string, cookies: list<array{string, string, string}>, hosts: array<string, list<string>>, retention: ?string, policy_url: ?string, script: ?string, config: array<string, scalar>, owner: string}>|null $declarations */
        $declarations = $container->getParameterBag()->all()[Services::PARAMETER] ?? null;

        if (null === $declarations || !$container->hasDefinition(self::LOADER)) {
            return;
        }

        $directories = [];

        foreach ($container->getDefinition(self::LOADER)->getMethodCalls() as $call) {
            $directory = \is_array($call) && 'addPath' === ($call[0] ?? null) && \is_array($call[1] ?? null) ? $call[1][0] ?? null : null;

            if (\is_string($directory)) {
                $real = realpath($directory);
                $directories[false === $real ? $directory : $real] = true;
            }
        }

        $projectDirectory = $container->getParameterBag()->all()['kernel.project_dir'] ?? '';
        $problems = ConsentLoads::find(ConsentLoads::templates(array_keys($directories), \is_string($projectDirectory) ? $projectDirectory : ''), array_values(array_filter(new Services($declarations)->declared(), static fn (\Quire\Privacy\Service $service): bool => $service->category->needsConsent())));

        if ([] !== $problems) {
            throw new InvalidArgumentException(\sprintf("The site's templates load services visitors are asked about before they consent:\n- %s", implode("\n- ", $problems)));
        }
    }
}
