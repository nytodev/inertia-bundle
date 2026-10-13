<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional\Protocol;

use Nytodev\InertiaBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class SharedPropsTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
    }

    public function testSharedPropsWhenShareCalledEmitsSharedPropsKeys(): void
    {
        $this->client->request('GET', '/test/shared-props-emit', [], [], [
            'HTTP_X-Inertia' => 'true',
        ]);

        $page = $this->decodeJsonPage();
        self::assertArrayHasKey('sharedProps', $page);
        self::assertSame(['auth', 'appName'], $page['sharedProps']);
    }

    public function testSharedPropsWhenShareCalledKeysAlsoPresentInProps(): void
    {
        $this->client->request('GET', '/test/shared-props-emit', [], [], [
            'HTTP_X-Inertia' => 'true',
        ]);

        $page = $this->decodeJsonPage();
        self::assertArrayHasKey('auth', $page['props']);
        self::assertArrayHasKey('appName', $page['props']);
    }

    public function testSharedPropsWhenNoSharedPropsFieldAbsent(): void
    {
        $this->client->request('GET', '/test/shared-props-empty', [], [], [
            'HTTP_X-Inertia' => 'true',
        ]);

        $page = $this->decodeJsonPage();
        self::assertArrayNotHasKey('sharedProps', $page);
    }

    public function testSharedPropsOnFirstVisitAlsoPresent(): void
    {
        $this->client->request('GET', '/test/shared-props-emit');

        $content = (string) $this->client->getResponse()->getContent();
        preg_match('/<script[^>]+type="application\/json"[^>]*>([^<]+)<\/script>/s', $content, $m);
        $page = json_decode($m[1] ?? '{}', true);

        self::assertIsArray($page);
        self::assertArrayHasKey('sharedProps', $page);
        self::assertSame(['auth', 'appName'], $page['sharedProps']);
    }

    public function testSharedDotKeysAreNestedAndExposedByFirstSegment(): void
    {
        $this->client->request('GET', '/test/shared-props-dot-keys', [], [], [
            'HTTP_X-Inertia' => 'true',
        ]);

        $page = $this->decodeJsonPage();
        self::assertSame(['user' => ['name' => 'Alice', 'role' => 'admin'], 'permissions' => ['edit-posts']], $page['props']['auth']);
        self::assertSame('MyApp', $page['props']['appName']);
        self::assertArrayNotHasKey('auth.user', $page['props']);
        self::assertSame(['auth', 'appName'], $page['sharedProps']);
    }

    public function testSharedDotKeysAreNestedOnFirstVisit(): void
    {
        $this->client->request('GET', '/test/shared-props-dot-keys');

        $content = (string) $this->client->getResponse()->getContent();
        preg_match('/<script[^>]+type="application\/json"[^>]*>([^<]+)<\/script>/s', $content, $m);
        $page = json_decode($m[1] ?? '{}', true);

        self::assertIsArray($page);
        self::assertSame(['name' => 'Alice', 'role' => 'admin'], $page['props']['auth']['user']);
        self::assertSame(['auth', 'appName'], $page['sharedProps']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonPage(): array
    {
        $content = (string) $this->client->getResponse()->getContent();
        $page = json_decode($content, true);
        self::assertIsArray($page);

        return $page;
    }
}
