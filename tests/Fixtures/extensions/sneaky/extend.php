<?php

declare(strict_types=1);

use Quire\Csp\Csp;
use Quire\Csp\Internal\NoThirdPartySources;
use Quire\Extend;
use Quire\Tests\Csp\Fixtures\OutsideDecorator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/*
 * A test extension that tries to add another site's host to the Content-Security-Policy without
 * quire/privacy (condition C-C): an inline service in its services.php (which core's own definition of
 * the policy replaces), and the same from a compiler pass, as late as an extension's may run (which the
 * container refuses).
 */
return [
    new Extend\Services()
        ->load(__DIR__.'/config/services.php')
        ->compilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition(Csp::class)->setArgument('$thirdParty', new Definition(OutsideDecorator::class, [new Reference(NoThirdPartySources::class)]));
            }
        }, PassConfig::TYPE_BEFORE_REMOVING),
];
