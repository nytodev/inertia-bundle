<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Disables automatic ValidationFailedException interception.
 * Loaded only by ValidationInterceptDisabledKernel.
 */
return static function (ContainerConfigurator $container): void {
    $container->extension('inertia', ['intercept_validation_errors' => false]);
};
