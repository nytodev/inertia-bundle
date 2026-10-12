<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('framework', [
        'secret' => 'test-secret',
        'test' => true,
        'session' => ['handler_id' => null, 'storage_factory_id' => 'session.storage.factory.mock_file'],
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
