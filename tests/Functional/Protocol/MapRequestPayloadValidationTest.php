<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional\Protocol;

use Nytodev\InertiaBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Validation errors thrown before the controller (MapRequestPayload / MapQueryString)
 * or manually (ValidationFailedException) must be converted into the standard
 * Inertia error flow: store errors in session, redirect back (303), inject as
 * the 'errors' prop on the next render.
 */
final class MapRequestPayloadValidationTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->client->followRedirects(false);
        $this->client->setServerParameter('HTTP_X_INERTIA', 'true');
    }

    // -------------------------------------------------------------------------
    // invalid payload → 303 redirect back to referer → errors in props
    // -------------------------------------------------------------------------

    public function testInvalidPayloadInertiaRequestRedirectsBackWithErrorsInProps(): void
    {
        $this->submitInvalidPayload(referer: 'http://localhost/test/map-request-payload');

        self::assertResponseStatusCodeSame(303);
        self::assertSame(
            'http://localhost/test/map-request-payload',
            $this->client->getResponse()->headers->get('Location'),
        );

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $errors = $this->decodeJsonResponse()['props']['errors'];
        self::assertArrayHasKey('email', $errors);
        self::assertArrayHasKey('age', $errors);
        self::assertIsString($errors['email']);
        self::assertIsString($errors['age']);
    }

    // -------------------------------------------------------------------------
    // several violations on the same field → only the first message is kept
    // -------------------------------------------------------------------------

    public function testInvalidPayloadMultipleViolationsSameFieldKeepsFirstMessageOnly(): void
    {
        $this->submitInvalidPayload(referer: 'http://localhost/test/map-request-payload');

        $this->client->followRedirect();

        // 'code' => 'abc' violates both Length(min: 5) and Regex — one string expected.
        $errors = $this->decodeJsonResponse()['props']['errors'];
        self::assertArrayHasKey('code', $errors);
        self::assertIsString($errors['code']);
    }

    // -------------------------------------------------------------------------
    // no Referer header → redirect to the current URL instead
    // -------------------------------------------------------------------------

    public function testInvalidPayloadWithoutRefererRedirectsToCurrentUrl(): void
    {
        $this->submitInvalidPayload(referer: null);

        self::assertResponseStatusCodeSame(303);
        self::assertSame(
            'http://localhost/test/map-request-payload',
            $this->client->getResponse()->headers->get('Location'),
        );
    }

    // -------------------------------------------------------------------------
    // cross-origin or malformed Referer → never used as redirect target
    // -------------------------------------------------------------------------

    public function testInvalidPayloadWithCrossOriginRefererRedirectsToCurrentUrl(): void
    {
        $this->submitInvalidPayload(referer: 'http://evil.example.com/phishing');

        self::assertResponseStatusCodeSame(303);
        self::assertSame(
            'http://localhost/test/map-request-payload',
            $this->client->getResponse()->headers->get('Location'),
        );
    }

    public function testInvalidPayloadWithSchemeRelativeRefererRedirectsToCurrentUrl(): void
    {
        $this->submitInvalidPayload(referer: '//evil.example.com/phishing');

        self::assertResponseStatusCodeSame(303);
        self::assertSame(
            'http://localhost/test/map-request-payload',
            $this->client->getResponse()->headers->get('Location'),
        );
    }

    public function testInvalidPayloadWithRelativePathRefererIsHonored(): void
    {
        $this->submitInvalidPayload(referer: '/test/map-request-payload?step=2');

        self::assertResponseStatusCodeSame(303);
        self::assertSame(
            '/test/map-request-payload?step=2',
            $this->client->getResponse()->headers->get('Location'),
        );
    }

    // -------------------------------------------------------------------------
    // X-Inertia-Error-Bag header → errors wrapped under the bag name
    // -------------------------------------------------------------------------

    public function testInvalidPayloadWithErrorBagHeaderWrapsErrorsUnderBagName(): void
    {
        $this->client->setServerParameter('HTTP_X_INERTIA_ERROR_BAG', 'registration');
        $this->submitInvalidPayload(referer: 'http://localhost/test/map-request-payload');

        $this->client->followRedirect();

        $errors = $this->decodeJsonResponse()['props']['errors'];
        self::assertArrayHasKey('registration', $errors);
        self::assertArrayHasKey('email', $errors['registration']);
    }

    // -------------------------------------------------------------------------
    // stateless route → session must not be touched, Symfony returns 422
    // -------------------------------------------------------------------------

    public function testInvalidPayloadOnStatelessRouteKeepsSymfony422(): void
    {
        $this->client->catchExceptions(true);
        $this->client->request(
            'POST',
            '/test/map-request-payload-stateless',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_REFERER' => 'http://localhost/test/map-request-payload'],
            (string) json_encode(['email' => '', 'age' => -1, 'code' => 'abc']),
        );

        self::assertResponseStatusCodeSame(422);
        self::assertResponseNotHasHeader('Location');
    }

    // -------------------------------------------------------------------------
    // non-Inertia request → untouched, Symfony returns 422
    // -------------------------------------------------------------------------

    public function testInvalidPayloadNonInertiaRequestKeeps422(): void
    {
        $this->client->setServerParameters([]);
        $this->client->request(
            'POST',
            '/test/map-request-payload',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['email' => '', 'age' => -1]),
        );

        self::assertResponseStatusCodeSame(422);
    }

    // -------------------------------------------------------------------------
    // valid payload → controller runs, no interception
    // -------------------------------------------------------------------------

    public function testValidPayloadControllerRunsAndRedirectsNormally(): void
    {
        $this->client->request(
            'POST',
            '/test/map-request-payload',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['email' => 'alice@example.com', 'age' => 30, 'code' => '12345']),
        );

        self::assertResponseStatusCodeSame(303);
        self::assertSame(
            '/test/validation-errors-target',
            $this->client->getResponse()->headers->get('Location'),
        );

        $this->client->followRedirect();
        self::assertSame([], $this->decodeJsonResponse()['props']['errors']);
    }

    // -------------------------------------------------------------------------
    // ValidationFailedException thrown manually in a controller → same flow
    // -------------------------------------------------------------------------

    public function testManualValidationFailedExceptionRedirectsBackWithErrors(): void
    {
        $this->client->request(
            'POST',
            '/test/throw-validation-failed',
            [],
            [],
            ['HTTP_REFERER' => 'http://localhost/test/map-request-payload'],
        );

        self::assertResponseStatusCodeSame(303);

        $this->client->followRedirect();

        $errors = $this->decodeJsonResponse()['props']['errors'];
        self::assertSame(['name' => 'Manual message.'], $errors);
    }

    // -------------------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------------------

    private function submitInvalidPayload(?string $referer): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $referer) {
            $server['HTTP_REFERER'] = $referer;
        }

        $this->client->request(
            'POST',
            '/test/map-request-payload',
            [],
            [],
            $server,
            (string) json_encode(['email' => '', 'age' => -1, 'code' => 'abc']),
        );
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
