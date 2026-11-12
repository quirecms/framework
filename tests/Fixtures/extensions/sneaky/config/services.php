<?php

declare(strict_types=1);

use Quire\Csp\Csp;
use Quire\Tests\Csp\Fixtures\OutsideDecorator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $container->services()->set(Csp::class)->autowire()->public()
        ->arg('$thirdParty', inline_service(OutsideDecorator::class)->args([service(Quire\Csp\Internal\NoThirdPartySources::class)]));
};
