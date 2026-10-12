<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional\Protocol;

use Nytodev\InertiaBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * MapQueryString validation failures wrap ValidationFailedException in a 404
 * HttpException (unlike MapRequestPayload's 422). The listener unwraps via
 * HttpExceptionInterface regardless of status code, so the Inertia error flow
 * must apply the same way: errors in session, 303 redirect back.
 */
final class MapQueryStringValidationTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->client->followRedirects(false);
    }

    // -------------------------------------------------------------------------
    // invalid query string → 303 redirect back to referer → errors in props
    // -------------------------------------------------------------------------

    public function testInvalidQueryStringInertiaRequestRedirectsBackWithErrorsInProps(): void
    {
        $this->client->request(
            'GET',
            '/test/map-query-string?term=',
            [],
            [],
            ['HTTP_REFERER' => 'http://localhost/test/map-request-payload', 'HTTP_X_INERTIA' => 'true'],
        );

        self::assertResponseStatusCodeSame(303);
        self::assertSame(
            'http://localhost/test/map-request-payload',
            $this->client->getResponse()->headers->get('Location'),
        );

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $errors = $this->decodeJsonResponse()['props']['errors'];
        self::assertArrayHasKey('term', $errors);
        self::assertIsString($errors['term']);
    }

    // -------------------------------------------------------------------------
    // valid query string → controller runs, renders normally
    // -------------------------------------------------------------------------

    public function testValidQueryStringInertiaRequestReachesController(): void
    {
        $this->client->request('GET', '/test/map-query-string?term=hello', [], [], ['HTTP_X_INERTIA' => 'true']);

        self::assertResponseIsSuccessful();
        self::assertSame('hello', $this->decodeJsonResponse()['props']['term']);
    }

    // -------------------------------------------------------------------------
    // non-Inertia request → untouched, Symfony returns its default 404
    // -------------------------------------------------------------------------

    public function testInvalidQueryStringNonInertiaRequestKeepsSymfony404(): void
    {
        $this->client->catchExceptions(true);

        $this->client->request(
            'GET',
            '/test/map-query-string?term=',
            [],
            [],
            ['HTTP_REFERER' => 'http://localhost/test/map-request-payload'],
        );

        self::assertResponseStatusCodeSame(404);
        self::assertResponseNotHasHeader('Location');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
