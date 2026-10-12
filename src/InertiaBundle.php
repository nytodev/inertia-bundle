<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle;

use Nytodev\InertiaBundle\Ssr\BundleDetector;
use Nytodev\InertiaBundle\Ssr\HttpSsrGateway;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Main bundle class. Uses AbstractBundle (Symfony 6.1+) — no separate Extension class needed.
 *
 * configure()     → defines the config schema (compile-time only)
 * loadExtension() → loads services and injects config values (compile-time only)
 *
 * @see config/definition.php  Config schema
 * @see config/services.yaml   Service definitions
 */
final class InertiaBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->import('../config/definition.php');
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.yaml');

        // $config is already merged and validated by AbstractBundle — use directly.
        // Never call processConfiguration() here.
        // Inject config values directly onto service definitions (not as global container parameters).
        $services = $container->services();

        $services->get('inertia.response')
            ->arg('$rootView', $config['root_view']);

        $services->get('inertia.service')
            ->arg('$version', $config['version'])
            ->arg('$defaultEncryptHistory', $config['encrypt_history'])
            ->arg('$exposeSharedPropKeys', $config['expose_shared_prop_keys'])
            ->arg('$ensurePagesExist', $config['pages']['ensure_pages_exist'])
            ->arg('$pagePaths', $config['pages']['paths'])
            ->arg('$pageExtensions', $config['pages']['extensions']);

        if (!$config['intercept_validation_errors'] || !class_exists(ValidationFailedException::class)) {
            $builder->removeDefinition('inertia.validation_listener');
        }

        // BundleDetector is always registered: used by StartSsrCommand and HttpSsrGateway.
        $services->get('inertia.ssr_bundle_detector')
            ->arg('$ssrBundle', $config['ssr_bundle'])
            ->arg('$projectDir', '%kernel.project_dir%');

        // start-ssr: always available (spawns a Node process, no HTTP client needed).
        // BundleDetector is injected — already configured above with ssr_bundle + project_dir.

        if ($config['ssr_enabled']) {
            // Replace NullSsrGateway with the real HTTP gateway, wired with all dependencies.
            $services->get('inertia.ssr_gateway')
                ->class(HttpSsrGateway::class)
                ->arg('$httpClient', service('http_client'))
                ->arg('$ssrUrl', $config['ssr_url'])
                ->arg('$dispatcher', service('event_dispatcher'))
                ->arg('$bundleDetector', service('inertia.ssr_bundle_detector'))
                ->arg('$throwOnError', $config['ssr_throw_on_error'])
                ->arg('$requestStack', service('request_stack'));

            // stop-ssr and check-ssr need symfony/http-client — only wire when SSR is enabled.
            $services->get('inertia.command.stop_ssr')
                ->arg('$httpClient', service('http_client'))
                ->arg('$ssrUrl', $config['ssr_url']);

            $services->get('inertia.command.check_ssr')
                ->arg('$httpClient', service('http_client'))
                ->arg('$ssrUrl', $config['ssr_url']);
        } else {
            $builder->removeDefinition('inertia.command.stop_ssr');
            $builder->removeDefinition('inertia.command.check_ssr');
        }
    }
}
