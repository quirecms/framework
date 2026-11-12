<?php

declare(strict_types=1);

namespace Quire\Csp\Internal;

use Quire\Csp\ThirdPartySourcesInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * Who may add other sites' hosts to the policy (doc 20, condition C-C): `quire/privacy` through
 * {@see ThirdPartySourcesInterface}, and core's own parts through {@see FirstPartySourcesInterface}.
 * Anything else stops the container from being built, with the reason: a host added elsewhere would
 * load before the visitor's consent, and be missing from the privacy page.
 *
 * Extensions run PHP, so this can't stop one that means to get round it; it makes the rule hold for
 * every extension that follows the API, and says so to one that tries.
 *
 * @internal
 */
final class CspSourcesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasAlias(ThirdPartySourcesInterface::class) || $container->hasDefinition(ThirdPartySourcesInterface::class)) {
            $class = $this->class($container, ThirdPartySourcesInterface::class);

            if (NoThirdPartySources::class !== $class && !str_starts_with($class, ThirdPartySourcesInterface::NAMESPACE)) {
                throw new InvalidArgumentException(\sprintf('"%s" adds other sites\' hosts to the Content-Security-Policy. Only quire/privacy may (its services are what visitors are asked about): declare the service through quire/privacy instead. See docs/security.md.', $class));
            }
        }

        $core = \dirname(__DIR__, 2).\DIRECTORY_SEPARATOR;

        foreach (array_keys($container->findTaggedServiceIds(FirstPartySourcesInterface::TAG)) as $id) {
            $class = $this->class($container, $id);
            $file = $container->getReflectionClass($class, false)?->getFileName();

            if (!\is_string($file) || !str_starts_with($file, $core)) {
                throw new InvalidArgumentException(\sprintf('"%s" adds sources to the Content-Security-Policy through an interface of Quire\'s own: only core\'s parts may. Other sites\' hosts come from quire/privacy\'s services, the site\'s from "quire.csp.sources". See docs/security.md.', $class));
            }
        }
    }

    private function class(ContainerBuilder $container, string $id): string
    {
        $definition = $container->findDefinition($id);
        $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);

        return \is_string($class) ? $class : $id;
    }
}
