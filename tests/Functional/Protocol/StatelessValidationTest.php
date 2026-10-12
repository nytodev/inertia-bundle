<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional\Protocol;

use Nytodev\InertiaBundle\Tests\Functional\app\StatelessKernel;
use Nytodev\InertiaBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Without a session, errors cannot survive a redirect — Inertia::errors()
 * falls back to in-memory storage that is lost on the next request. The
 * listener must skip interception and let Symfony return its default 4xx,
 * instead of redirecting to a page with silently empty errors.
 */
final class StatelessValidationTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new StatelessKernel();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->client->followRedirects(false);
        $this->client->catchExceptions(true);
    }

    public function testInvalidPayloadWithoutSessionKeepsSymfony422(): void
    {
        $this->client->request(
            'POST',
            '/test/map-request-payload',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_INERTIA' => 'true',
                'HTTP_REFERER' => 'http://localhost/test/map-request-payload',
            ],
            (string) json_encode(['email' => '', 'age' => -1, 'code' => 'abc']),
        );

        self::assertResponseStatusCodeSame(422);
        self::assertResponseNotHasHeader('Location');
    }
}
