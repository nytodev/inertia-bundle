<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional\Protocol;

use Nytodev\InertiaBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class FirstVisitTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
    }

    public function testFirstVisitWithoutXInertiaHeaderReturnsFullHtml(): void
    {
        $this->client->request('GET', '/test');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<div id="app">', (string) $this->client->getResponse()->getContent());
    }

    public function testFirstVisitHtmlContainsScriptTag(): void
    {
        $this->client->request('GET', '/test');
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<script data-page="app" type="application/json">', $content);
    }

    public function testFirstVisitDivIsEmpty(): void
    {
        $this->client->request('GET', '/test');
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<div id="app"></div>', $content);
    }

    public function testFirstVisitDataPageAttributeIsOnScriptNotDiv(): void
    {
        $this->client->request('GET', '/test');
        $content = (string) $this->client->getResponse()->getContent();
        // data-page must NOT appear on the div
        self::assertDoesNotMatchRegularExpression('/<div[^>]+data-page/', $content);
    }

    public function testFirstVisitJsonEscapesClosingScriptTag(): void
    {
        // JSON_HEX_TAG must encode < and > so </script> cannot break out of the script block
        $this->client->request('GET', '/test');
        $content = (string) $this->client->getResponse()->getContent();
        $matched = preg_match('/<script[^>]+type="application\/json"[^>]*>([^<]*)<\/script>/s', $content, $m);
        self::assertSame(1, $matched, '<script type="application/json"> tag not found');
        self::assertStringNotContainsString('</script>', $m[1]);
    }

    public function testFirstVisitPageObjectContainsAllRequiredV3Fields(): void
    {
        $this->client->request('GET', '/test');
        $page = $this->extractPageObject();
        self::assertArrayHasKey('component', $page);
        self::assertArrayHasKey('props', $page);
        self::assertArrayHasKey('url', $page);
        self::assertArrayHasKey('version', $page);
    }

    public function testFirstVisitClearHistoryAbsentWhenFalseInV3(): void
    {
        $this->client->request('GET', '/test');
        $page = $this->extractPageObject();
        self::assertArrayNotHasKey('clearHistory', $page);
    }

    public function testFirstVisitEncryptHistoryAbsentWhenFalseInV3(): void
    {
        $this->client->request('GET', '/test');
        $page = $this->extractPageObject();
        self::assertArrayNotHasKey('encryptHistory', $page);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPageObject(): array
    {
        $content = (string) $this->client->getResponse()->getContent();
        $matched = preg_match('/<script[^>]+type="application\/json"[^>]*>([^<]+)<\/script>/s', $content, $matches);
        self::assertSame(1, $matched, '<script type="application/json"> tag not found in response');
        $json = $matches[1];
        $page = json_decode($json, true);
        self::assertIsArray($page);

        return $page;
    }
}
