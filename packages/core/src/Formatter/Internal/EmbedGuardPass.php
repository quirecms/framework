<?php

declare(strict_types=1);

namespace Quire\Formatter\Internal;

use Quire\Csp\Internal\PrivacySlotPass;
use Quire\Formatter\EmbedGuardInterface;
use Quire\Formatter\Formatter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * Who may guard the embeds of trusted shortcodes (doc 20, condition C-B): core's default, which leaves
 * them as they are, or quire/privacy's, which holds them until the visitor consents. The site's policy
 * allows a service's hosts for every visitor, so another guard (one that does nothing) would let every
 * embed load before consent: it stops the container from being built, with the reason. Checked as
 * {@see \Quire\Csp\Internal\CspSourcesPass} checks the policy's sources: the slot, and what the
 * formatter is actually given, by reference or inline, with its factory and configurator.
 *
 * @internal
 */
final readonly class EmbedGuardPass extends PrivacySlotPass
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasAlias(EmbedGuardInterface::class) || $container->hasDefinition(EmbedGuardInterface::class)) {
            $this->assertGuard($container, $this->class($container, EmbedGuardInterface::class));
        }

        if (!$container->hasDefinition(Formatter::class)) {
            return;
        }

        $formatter = $container->getDefinition(Formatter::class);
        $this->assertBuiltBy($container, $formatter, Formatter::class, $this->assertGuard(...), 'The formatter service');
        $arguments = $formatter->getArguments();
        $position = 0;

        foreach (new \ReflectionMethod(Formatter::class, '__construct')->getParameters() as $parameter) {
            if ('embeds' === $parameter->getName()) {
                $position = $parameter->getPosition();
            }
        }

        foreach ($this->definitions($container, [$arguments['$embeds'] ?? $arguments[$position] ?? null]) as $definition) {
            $this->assertGuard($container, $this->classOf($container, $definition));
            $this->assertBuiltBy($container, $definition, null, $this->assertGuard(...), '');
        }
    }

    private function assertGuard(ContainerBuilder $container, string $class): void
    {
        if (NoEmbedGuard::class !== $class && !$this->isPrivacy($container, $class)) {
            throw new InvalidArgumentException(\sprintf('"%s" guards the embeds of trusted shortcodes. Only quire/privacy may (it holds them until the visitor consents): an embed that needs consent goes through it. See docs/security.md.', $class));
        }
    }
}
