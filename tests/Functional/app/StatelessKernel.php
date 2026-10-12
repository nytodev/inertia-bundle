<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional\app;

use Nytodev\InertiaBundle\InertiaBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Test kernel variant without session support.
 */
final class StatelessKernel extends Kernel
{
    public function __construct()
    {
        parent::__construct('test', false);
    }

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TwigBundle(), new InertiaBundle()];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(__DIR__.'/config/framework_stateless.php');
        $loader->load(__DIR__.'/config/twig.php');
        $loader->load(__DIR__.'/config/routing.php');
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/inertia-bundle-tests/cache/stateless';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/inertia-bundle-tests/logs';
    }
}
