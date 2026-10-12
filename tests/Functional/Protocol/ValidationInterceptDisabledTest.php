<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional\Protocol;

use Nytodev\InertiaBundle\Tests\Functional\app\ValidationInterceptDisabledKernel;
use Nytodev\InertiaBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * With inertia.intercept_validation_errors: false, the bundle must not touch
 * ValidationFailedException — Symfony's default 422 behavior is preserved even
 * on Inertia requests, and the listener service is removed from the container.
 */
final class ValidationInterceptDisabledTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new ValidationInterceptDisabledKernel();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->client->followRedirects(false);
        $this->client->setServerParameter('HTTP_X_INERTIA', 'true');
    }

    public function testInvalidPayloadInterceptDisabledKeepsSymfony422(): void
    {
        $this->client->catchExceptions(true);
        $this->client->request(
            'POST',
            '/test/map-request-payload',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_REFERER' => 'http://localhost/test/map-request-payload'],
            (string) json_encode(['email' => '', 'age' => -1, 'code' => 'abc']),
        );

        self::assertResponseStatusCodeSame(422);
        self::assertResponseNotHasHeader('Location');
    }

    public function testValidationListenerInterceptDisabledServiceIsRemoved(): void
    {
        self::assertFalse(self::getContainer()->has('inertia.validation_listener'));
    }
}
