<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Framework configuration without session support.
 * Loaded only by StatelessKernel.
 */
return static function (ContainerConfigurator $container): void {
    $container->extension('framework', [
        'secret' => 'test-secret',
        'test' => true,
        'session' => ['enabled' => false],
        'router' => ['utf8' => true],
        'serializer' => ['enabled' => true],
        'validation' => ['enabled' => true],
        'property_access' => ['enabled' => true],
    ]);

    $container->services()
        ->set('Nytodev\InertiaBundle\Tests\Functional\TestController')
        ->autowire(true)
        ->tag('controller.service_arguments');
};
