<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional\Protocol;

use Nytodev\InertiaBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ValidationErrorsTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
    }

    // -------------------------------------------------------------------------
    // errors are auto-injected from Inertia::errors() call
    // -------------------------------------------------------------------------

    public function testErrorsAutoInjectedFromServiceWhenErrorsSetAppearsInProps(): void
    {
        $this->client->request('GET', '/test/validation-errors', [], [], ['HTTP_X_INERTIA' => 'true']);

        self::assertResponseIsSuccessful();
        $data = $this->decodeJsonResponse();
        self::assertSame(['email' => 'Invalid email'], $data['props']['errors']);
    }

    // -------------------------------------------------------------------------
    // errors survive a redirect (PRG pattern — stored in session)
    // -------------------------------------------------------------------------

    public function testErrorsSurviveRedirectPrgPatternErrorsInPropsAfterRedirect(): void
    {
        $this->client->followRedirects(false);
        $this->client->setServerParameter('HTTP_X_INERTIA', 'true');

        // PUT → controller sets errors + returns 302 → listener converts to 303
        $this->client->request('PUT', '/test/validation-errors-redirect');
        self::assertResponseStatusCodeSame(303);
        self::assertSame('/test/validation-errors-target', $this->client->getResponse()->headers->get('Location'));

        // Follow redirect → GET /test/validation-errors-target → errors must be in props
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $data = $this->decodeJsonResponse();
        self::assertSame(['email' => 'Invalid email'], $data['props']['errors']);
    }

    // -------------------------------------------------------------------------
    // errors are consumed (cleared) after render
    // -------------------------------------------------------------------------

    public function testErrorsConsumedAfterRenderSecondRequestErrorsEmpty(): void
    {
        // First request: errors appear
        $this->client->request('GET', '/test/validation-errors', [], [], ['HTTP_X_INERTIA' => 'true']);
        $data = $this->decodeJsonResponse();
        self::assertSame(['email' => 'Invalid email'], $data['props']['errors']);

        // Second request to a render-only route: errors must be empty
        $this->client->request('GET', '/test/validation-errors-target', [], [], ['HTTP_X_INERTIA' => 'true']);
        $data = $this->decodeJsonResponse();
        self::assertSame([], $data['props']['errors']);
    }

    // -------------------------------------------------------------------------
    // named bag: errors are keyed under the bag name
    // -------------------------------------------------------------------------

    public function testErrorsNamedBagWhenBagNameGivenKeyedInProps(): void
    {
        $this->client->request('GET', '/test/validation-errors-named-bag', [], [], ['HTTP_X_INERTIA' => 'true']);

        self::assertResponseIsSuccessful();
        $data = $this->decodeJsonResponse();
        self::assertSame(['login' => ['name' => 'Required']], $data['props']['errors']);
    }

    // -------------------------------------------------------------------------
    // X-Inertia-Error-Bag header wraps default bag errors under the header value
    // -------------------------------------------------------------------------

    public function testXInertiaErrorBagHeaderWithDefaultBagWrapsUnderHeaderName(): void
    {
        $this->client->request('GET', '/test/validation-errors', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_ERROR_BAG' => 'registration',
        ]);

        self::assertResponseIsSuccessful();
        $data = $this->decodeJsonResponse();
        self::assertSame(['registration' => ['email' => 'Invalid email']], $data['props']['errors']);
    }

    // -------------------------------------------------------------------------
    // default bag + named bag: only default is sent, like inertia-laravel —
    // the client reads errors[errorBag] or the flat object, never bag names
    // -------------------------------------------------------------------------

    public function testMixedBagsWithoutHeaderSendsOnlyDefaultBagFlat(): void
    {
        $this->client->request('GET', '/test/validation-errors-mixed-bags', [], [], ['HTTP_X_INERTIA' => 'true']);

        self::assertResponseIsSuccessful();
        self::assertSame(['email' => 'Invalid email'], $this->decodeJsonResponse()['props']['errors']);
    }

    public function testMixedBagsWithErrorBagHeaderWrapsOnlyDefaultBag(): void
    {
        $this->client->request('GET', '/test/validation-errors-mixed-bags', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_ERROR_BAG' => 'registration',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(['registration' => ['email' => 'Invalid email']], $this->decodeJsonResponse()['props']['errors']);
    }

    // -------------------------------------------------------------------------
    // FlashBag 'errors' reach the next render, whether it is a first visit or XHR
    // -------------------------------------------------------------------------

    public function testFlashBagErrorsAppearOnFirstVisitAfterClassicFormPost(): void
    {
        $this->client->followRedirects(false);
        $this->client->request('POST', '/test/flash-bag-errors-redirect');
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        $this->assertInertia($this->client->getResponse(), static function (\Nytodev\InertiaBundle\Testing\AssertableInertiaPage $page): void {
            $page->where('errors', ['email' => 'Invalid email']);
        });

        $this->client->request('GET', '/test/validation-errors-target', [], [], ['HTTP_X_INERTIA' => 'true']);
        self::assertSame([], $this->decodeJsonResponse()['props']['errors']);
    }

    // -------------------------------------------------------------------------
    // explicit errors in render() props wins over auto-injection
    // -------------------------------------------------------------------------

    public function testExplicitErrorsInRenderPropsWinsOverAutoInjection(): void
    {
        $this->client->request('GET', '/test/validation-errors-override', [], [], ['HTTP_X_INERTIA' => 'true']);

        self::assertResponseIsSuccessful();
        $data = $this->decodeJsonResponse();
        self::assertSame(['explicit' => true], $data['props']['errors']);
    }

    // -------------------------------------------------------------------------
    // errors is empty object by default when no errors set
    // -------------------------------------------------------------------------

    public function testErrorsIsEmptyByDefaultWhenNoErrorsSetPropsErrorsIsEmptyObject(): void
    {
        $this->client->request('GET', '/test', [], [], ['HTTP_X_INERTIA' => 'true']);

        self::assertResponseIsSuccessful();
        $data = $this->decodeJsonResponse();
        self::assertArrayHasKey('errors', $data['props']);
        self::assertSame([], $data['props']['errors']);
    }

    public function testEmptyErrorsOnXhrVisitRawJsonIsObjectNotArray(): void
    {
        $this->client->request('GET', '/test', [], [], ['HTTP_X_INERTIA' => 'true']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"errors":{}', (string) $this->client->getResponse()->getContent());
    }

    public function testEmptyErrorsOnFirstVisitRawJsonIsObjectNotArray(): void
    {
        $this->client->request('GET', '/test');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"errors":{}', (string) $this->client->getResponse()->getContent());
    }

    // -------------------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------------------

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
