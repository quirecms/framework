<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal;

use Quire\Privacy\Services;
use Quire\Privacy\ServiceSwitchInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * Refuses to build the site with a switch of a service that the switch's package didn't declare, or a
 * second switch of one: otherwise a theme or another extension could turn on a service, or give
 * Google Analytics its own measurement ID. The site ignores such a switch too ({@see Services}).
 *
 * @internal
 */
final readonly class ServiceSwitchesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        /** @var list<array{id: string, switched?: bool, runtime?: list<string>, path?: string}> $declared */
        $declared = $container->getParameterBag()->all()[Services::PARAMETER] ?? [];
        $declarations = [];

        foreach ($declared as $declaration) {
            $declarations[$declaration['id']] = ['switched' => $declaration['switched'] ?? false, 'runtime' => $declaration['runtime'] ?? [], 'path' => $declaration['path'] ?? ''];
        }

        $taken = [];

        foreach (array_keys($container->findTaggedServiceIds(ServiceSwitchInterface::TAG)) as $id) {
            $class = $container->getParameterBag()->resolveValue($container->findDefinition($id)->getClass() ?? $id);

            if (!\is_string($class) || !is_subclass_of($class, ServiceSwitchInterface::class)) {
                throw new InvalidArgumentException(\sprintf('The service "%s" is tagged "%s" but isn\'t a %s.', $id, ServiceSwitchInterface::TAG, ServiceSwitchInterface::class));
            }

            $problem = Services::switchProblem($class, $declarations, $taken);

            if (null !== $problem) {
                throw new InvalidArgumentException($problem.' See docs/privacy.md.');
            }

            $taken[$class::service()] = true;
        }
    }
}
