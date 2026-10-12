<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional\Protocol;

use Nytodev\InertiaBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class FlashTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
    }

    // -------------------------------------------------------------------------
    // flash absent entirely when no flash data (matching inertia-laravel)
    // -------------------------------------------------------------------------

    public function testFlashIsAbsentWhenNoFlashSetXhrRequest(): void
    {
        $this->client->request('GET', '/test', [], [], ['HTTP_X_INERTIA' => 'true']);
        self::assertResponseIsSuccessful();
        $data = $this->decodeJsonResponse();
        self::assertArrayNotHasKey('flash', $data);
        self::assertArrayNotHasKey('flash', $data['props']);
    }

    public function testFlashIsAbsentWhenNoFlashSetFirstVisit(): void
    {
        $this->client->request('GET', '/test');
        self::assertResponseIsSuccessful();
        $page = $this->extractPageObject();
        self::assertArrayNotHasKey('flash', $page);
        self::assertArrayNotHasKey('flash', $page['props']);
    }

    // -------------------------------------------------------------------------
    // flash data set on same request appears as top-level flash key (v3 protocol)
    // -------------------------------------------------------------------------

    public function testFlashDataAppearsAsTopLevelKeyXhrRequest(): void
    {
        $this->client->request('GET', '/test/flash-direct', [], [], ['HTTP_X_INERTIA' => 'true']);
        self::assertResponseIsSuccessful();
        $data = $this->decodeJsonResponse();
        self::assertSame(['status' => 'saved'], $data['flash']);
        self::assertArrayNotHasKey('flash', $data['props']);
    }

    public function testFlashDataAppearsAsTopLevelKeyFirstVisit(): void
    {
        $this->client->request('GET', '/test/flash-direct');
        self::assertResponseIsSuccessful();
        $page = $this->extractPageObject();
        self::assertSame(['status' => 'saved'], $page['flash']);
        self::assertArrayNotHasKey('flash', $page['props']);
    }

    // -------------------------------------------------------------------------
    // flash is consumed (cleared) after render
    // -------------------------------------------------------------------------

    public function testFlashIsConsumedAfterRenderSecondRequestShowsNoFlash(): void
    {
        // First request: flash is set and rendered
        $this->client->request('GET', '/test/flash-direct', [], [], ['HTTP_X_INERTIA' => 'true']);
        $data = $this->decodeJsonResponse();
        self::assertSame(['status' => 'saved'], $data['flash']);

        // Second request to a render-only route: flash key must be absent
        $this->client->request('GET', '/test/flash-target', [], [], ['HTTP_X_INERTIA' => 'true']);
        $data = $this->decodeJsonResponse();
        self::assertArrayNotHasKey('flash', $data);
    }

    // -------------------------------------------------------------------------
    // flash survives a redirect (stored in session, read on the next render)
    // -------------------------------------------------------------------------

    public function testFlashSurvivesRedirectFlashAppearsAfter303(): void
    {
        $this->client->followRedirects(false);
        // X-Inertia must be set via setServerParameter so followRedirect() inherits it.
        $this->client->setServerParameter('HTTP_X_INERTIA', 'true');

        // PUT → controller sets flash + returns 302 → listener converts to 303
        $this->client->request('PUT', '/test/flash-redirect');
        self::assertResponseStatusCodeSame(303);
        self::assertSame('/test/flash-target', $this->client->getResponse()->headers->get('Location'));

        // Follow redirect → GET /test/flash-target → flash must be top-level
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $data = $this->decodeJsonResponse();
        self::assertSame(['status' => 'saved'], $data['flash']);
        self::assertArrayNotHasKey('flash', $data['props']);
    }

    public function testFlashIsConsumedAfterRedirectRenderThirdRequestShowsNoFlash(): void
    {
        $this->client->followRedirects(false);
        $this->client->setServerParameter('HTTP_X_INERTIA', 'true');

        // PUT → 303 redirect
        $this->client->request('PUT', '/test/flash-redirect');
        self::assertResponseStatusCodeSame(303);

        // Follow redirect → flash present as top-level key
        $this->client->followRedirect();
        $data = $this->decodeJsonResponse();
        self::assertSame(['status' => 'saved'], $data['flash']);

        // Third request → flash consumed, key must be absent (setServerParameter keeps X-Inertia: true)
        $this->client->request('GET', '/test/flash-target');
        $data = $this->decodeJsonResponse();
        self::assertArrayNotHasKey('flash', $data);
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

    /**
     * Parse the page object JSON from a first-visit HTML response.
     *
     * @return array<string, mixed>
     */
    private function extractPageObject(): array
    {
        $content = (string) $this->client->getResponse()->getContent();
        $matched = preg_match('/<script[^>]+type="application\/json"[^>]*>([^<]+)<\/script>/s', $content, $matches);
        self::assertSame(1, $matched, '<script type="application/json"> tag not found in HTML response');
        $page = json_decode($matches[1], true);
        self::assertIsArray($page);

        return $page;
    }
}
