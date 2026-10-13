<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Unit;

use Nytodev\InertiaBundle\InertiaBundle;
use Nytodev\InertiaBundle\Ssr\HttpSsrGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class InertiaBundleTest extends TestCase
{
    public function testSsrGatewayChecksBundleExistsByDefault(): void
    {
        $gateway = $this->ssrGateway(['ssr_enabled' => true]);

        self::assertSame(HttpSsrGateway::class, $gateway->getClass());
        self::assertEquals(new Reference('inertia.ssr_bundle_detector'), $gateway->getArgument('$bundleDetector'));
        self::assertNull($gateway->getArgument('$timeout'));
    }

    public function testSsrGatewayWithoutBundleCheckHasNoDetector(): void
    {
        $gateway = $this->ssrGateway(['ssr_enabled' => true, 'ssr_ensure_bundle_exists' => false]);

        self::assertArrayNotHasKey('$bundleDetector', $gateway->getArguments());
    }

    public function testSsrGatewayReceivesConfiguredTimeout(): void
    {
        $gateway = $this->ssrGateway(['ssr_enabled' => true, 'ssr_timeout' => 5]);

        self::assertEquals(5.0, $gateway->getArgument('$timeout'));
    }

    public function testSsrTimeoutMustBePositive(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('is too small for path "inertia.ssr_timeout"');

        $this->ssrGateway(['ssr_enabled' => true, 'ssr_timeout' => 0]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function ssrGateway(array $config): Definition
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        $extension = (new InertiaBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([$config], $container);

        return $container->getDefinition('inertia.ssr_gateway');
    }
}
