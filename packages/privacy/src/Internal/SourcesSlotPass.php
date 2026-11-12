<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal;

use Quire\Csp\ThirdPartySourcesInterface;
use Quire\Formatter\EmbedGuardInterface;
use Quire\Privacy\Internal\Formatter\EmbedGuard;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fills core's one slot for other sites' hosts with the services' (condition C-C), and its slot for
 * holding trusted shortcodes' embeds until consent (C-B). A pass, because core sets its defaults after
 * extensions load their service files. Core's own check runs later and
 * accepts this class only from the installed quire/privacy.
 *
 * @internal
 */
final readonly class SourcesSlotPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $container->setAlias(ThirdPartySourcesInterface::class, ServiceSources::class);
        $container->setAlias(EmbedGuardInterface::class, EmbedGuard::class);
    }
}
