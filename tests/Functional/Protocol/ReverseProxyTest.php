<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional\Protocol;

use Nytodev\InertiaBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

/**
 * Ensures the Inertia page object `url` field reflects the public URL
 * as seen by the browser when the app runs behind a reverse proxy that
 * strips a path prefix and forwards it via X-Forwarded-Prefix.
 */
final class ReverseProxyTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], 0);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Without reverse proxy — regression guard
    // -------------------------------------------------------------------------

    public function testUrlWithoutProxyOnFirstVisit(): void
    {
        $this->client->request('GET', '/test');
        $page = $this->extractPageObjectFromHtml();
        self::assertSame('/test', $page['url']);
    }

    public function testUrlWithoutProxyOnXhrVisit(): void
    {
        $this->client->request('GET', '/test', [], [], ['HTTP_X_INERTIA' => 'true']);
        $data = $this->decodeJsonResponse();
        self::assertSame('/test', $data['url']);
    }

    public function testUrlWithoutProxyPreservesQueryStringOnXhrVisit(): void
    {
        $this->client->request('GET', '/test?page=3', [], [], ['HTTP_X_INERTIA' => 'true']);
        $data = $this->decodeJsonResponse();
        self::assertSame('/test?page=3', $data['url']);
    }

    public function testUrlWithoutProxyPreservesQueryStringOnFirstVisit(): void
    {
        $this->client->request('GET', '/test?page=3');
        $page = $this->extractPageObjectFromHtml();
        self::assertSame('/test?page=3', $page['url']);
    }

    public function testUrlWithoutProxyPreservesMultipleQueryParams(): void
    {
        $this->client->request('GET', '/test?page=2&sort=desc&filter=active', [], [], ['HTTP_X_INERTIA' => 'true']);
        $data = $this->decodeJsonResponse();
        self::assertSame('/test?filter=active&page=2&sort=desc', $data['url']);
    }

    public function testUrlWithNonEmptyBaseUrlWithoutProxy(): void
    {
        // Simulate app mounted under /sub (non-empty getBaseUrl()).
        // Verifies no URL doubling: getBaseUrl()+getPathInfo() must yield /sub/test, not /sub/sub/test.
        $this->client->request('GET', '/sub/test?page=2', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'SCRIPT_NAME' => '/sub/index.php',
            'SCRIPT_FILENAME' => '/var/www/html/sub/public/index.php',
            'PHP_SELF' => '/sub/index.php',
        ]);

        self::assertResponseIsSuccessful();
        $data = $this->decodeJsonResponse();
        self::assertSame('/sub/test?page=2', $data['url']);
    }

    // -------------------------------------------------------------------------
    // With reverse proxy forwarding X-Forwarded-Prefix
    // -------------------------------------------------------------------------

    public function testUrlIncludesForwardedPrefixOnFirstVisit(): void
    {
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_PREFIX);

        $this->client->request('GET', '/test', [], [], [
            'HTTP_X_FORWARDED_PREFIX' => '/app',
        ]);

        $page = $this->extractPageObjectFromHtml();
        self::assertSame('/app/test', $page['url']);
    }

    public function testUrlIncludesForwardedPrefixOnXhrVisit(): void
    {
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_PREFIX);

        $this->client->request('GET', '/test', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_FORWARDED_PREFIX' => '/app',
        ]);

        $data = $this->decodeJsonResponse();
        self::assertSame('/app/test', $data['url']);
    }

    public function testUrlIncludesForwardedPrefixAndQueryStringOnXhrVisit(): void
    {
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_PREFIX);

        $this->client->request('GET', '/test?page=2', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_FORWARDED_PREFIX' => '/app',
        ]);

        $data = $this->decodeJsonResponse();
        self::assertSame('/app/test?page=2', $data['url']);
    }

    public function testUrlIncludesForwardedPrefixAndQueryStringOnFirstVisit(): void
    {
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_PREFIX);

        $this->client->request('GET', '/test?page=2', [], [], [
            'HTTP_X_FORWARDED_PREFIX' => '/app',
        ]);

        $page = $this->extractPageObjectFromHtml();
        self::assertSame('/app/test?page=2', $page['url']);
    }

    public function testUrlIncludesForwardedPrefixWithMultipleQueryParams(): void
    {
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_PREFIX);

        $this->client->request('GET', '/test?page=2&sort=desc&filter=active', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_FORWARDED_PREFIX' => '/app',
        ]);

        $data = $this->decodeJsonResponse();
        self::assertSame('/app/test?filter=active&page=2&sort=desc', $data['url']);
    }

    public function testUntrustedProxyHeaderIsIgnored(): void
    {
        // Proxy not in trusted list — header must be silently ignored.
        $this->client->request('GET', '/test', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_FORWARDED_PREFIX' => '/should-be-ignored',
        ]);

        $data = $this->decodeJsonResponse();
        self::assertSame('/test', $data['url']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function extractPageObjectFromHtml(): array
    {
        $content = (string) $this->client->getResponse()->getContent();
        $matched = preg_match('/<script[^>]+type="application\/json"[^>]*>([^<]+)<\/script>/s', $content, $matches);
        self::assertSame(1, $matched, '<script type="application/json"> tag not found');
        $page = json_decode($matches[1], true);
        self::assertIsArray($page);

        return $page;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        return $data;
    }
}
