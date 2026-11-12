<?php

declare(strict_types=1);

use Quire\Privacy\Consent;
use Quire\Privacy\Services;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    // Declarations come from the extensions and themes that use this one, booted around it: keep any
    // made already (by one that declares its services only when this one is enabled).
    $declared = $builder->getParameterBag()->all()[Services::PARAMETER] ?? [];
    $builder->setParameter(Services::PARAMETER, is_array($declared) ? $declared : []);

    // What extensions use on the server, e.g. to drop an event the visitor didn't allow.
    $container->services()->get(Consent::class)->public();
};
