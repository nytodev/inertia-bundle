<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional\Protocol;

use Nytodev\InertiaBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class HistoryFlagsTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
    }

    public function testClearHistoryXhrRequestPageObjectHasClearHistoryTrue(): void
    {
        $this->client->request('GET', '/test/clear-history', [], [], [
            'HTTP_X-Inertia' => 'true',
            'HTTP_X-Inertia-Version' => 'abc123',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertTrue($data['clearHistory']);
        $this->assertArrayNotHasKey('encryptHistory', $data);
    }

    public function testEncryptHistoryXhrRequestPageObjectHasEncryptHistoryTrue(): void
    {
        $this->client->request('GET', '/test/encrypt-history', [], [], [
            'HTTP_X-Inertia' => 'true',
            'HTTP_X-Inertia-Version' => 'abc123',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayNotHasKey('clearHistory', $data);
        $this->assertTrue($data['encryptHistory']);
    }

    public function testBothFlagsAbsentByDefaultInV3(): void
    {
        $this->client->request('GET', '/test', [], [], [
            'HTTP_X-Inertia' => 'true',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayNotHasKey('clearHistory', $data);
        $this->assertArrayNotHasKey('encryptHistory', $data);
    }

    public function testClearHistoryHtmlRequestDataPageContainsClearHistoryTrue(): void
    {
        $this->client->request('GET', '/test/clear-history');

        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('<script data-page="app" type="application/json">', $content);

        // Extract page object JSON from the script tag
        $matched = preg_match('/<script[^>]+type="application\/json"[^>]*>([^<]+)<\/script>/s', $content, $matches);
        $this->assertSame(1, $matched, '<script type="application/json"> tag must be present');

        $data = json_decode($matches[1], true);
        $this->assertIsArray($data);
        $this->assertTrue($data['clearHistory']);
    }

    // -------------------------------------------------------------------------
    // clearHistory survives a redirect (stored in session, read on next render)
    // -------------------------------------------------------------------------

    public function testClearHistorySurvivesRedirectFlagPresentAfterFollowRedirect(): void
    {
        $this->client->followRedirects(false);
        // X-Inertia must be set via setServerParameter so followRedirect() inherits it.
        $this->client->setServerParameter('HTTP_X_INERTIA', 'true');

        // GET → controller calls clearHistory() then redirects
        $this->client->request('GET', '/test/clear-history-redirect');
        $this->assertResponseRedirects('/test');

        // Follow redirect → clearHistory must be true in page object
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertTrue($data['clearHistory']);
    }

    public function testClearHistoryIsConsumedAfterRedirectThirdRequestShowsNoClearHistory(): void
    {
        $this->client->followRedirects(false);
        $this->client->setServerParameter('HTTP_X_INERTIA', 'true');

        // GET → redirect
        $this->client->request('GET', '/test/clear-history-redirect');

        // Follow redirect → flag consumed
        $this->client->followRedirect();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['clearHistory']);

        // Third request → flag must be absent
        $this->client->request('GET', '/test');
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayNotHasKey('clearHistory', $data);
    }

    // -------------------------------------------------------------------------
    // preserveFragment
    // -------------------------------------------------------------------------

    public function testPreserveFragmentIsAbsentByDefault(): void
    {
        $this->client->request('GET', '/test', [], [], [
            'HTTP_X-Inertia' => 'true',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayNotHasKey('preserveFragment', $data);
    }

    public function testPreserveFragmentXhrRequestPageObjectHasPreserveFragmentTrue(): void
    {
        $this->client->request('GET', '/test/preserve-fragment', [], [], [
            'HTTP_X-Inertia' => 'true',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertTrue($data['preserveFragment']);
        $this->assertArrayNotHasKey('clearHistory', $data);
    }

    public function testPreserveFragmentSurvivesRedirectFlagPresentAfterFollowRedirect(): void
    {
        $this->client->followRedirects(false);
        $this->client->setServerParameter('HTTP_X_INERTIA', 'true');

        // GET → controller calls preserveFragment() then redirects
        $this->client->request('GET', '/test/preserve-fragment-redirect');
        $this->assertResponseRedirects('/test');

        // Follow redirect → preserveFragment must be true in page object
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertTrue($data['preserveFragment']);
    }

    public function testPreserveFragmentIsConsumedAfterRedirectThirdRequestShowsNoFlag(): void
    {
        $this->client->followRedirects(false);
        $this->client->setServerParameter('HTTP_X_INERTIA', 'true');

        // GET → redirect
        $this->client->request('GET', '/test/preserve-fragment-redirect');

        // Follow redirect → flag consumed
        $this->client->followRedirect();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['preserveFragment']);

        // Third request → flag must be absent
        $this->client->request('GET', '/test');
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayNotHasKey('preserveFragment', $data);
    }
}
