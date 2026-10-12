<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional\Protocol;

use Nytodev\InertiaBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class NestedPropsTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
    }

    public function testNestedClosuresOnXhrVisitAreResolved(): void
    {
        $this->client->request('GET', '/test/nested-props', [], [], ['HTTP_X_INERTIA' => 'true']);

        self::assertResponseIsSuccessful();
        $props = $this->decodeJsonResponse()['props'];
        self::assertSame(['name' => 'Tony'], $props['auth']['user']);
        self::assertSame(['read'], $props['auth']['permissions']);
        self::assertSame(['count' => 42, 'label' => 'visits'], $props['stats']);
    }

    public function testNestedClosuresOnFirstVisitAreResolvedInScriptTag(): void
    {
        $this->client->request('GET', '/test/nested-props');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"user":{"name":"Tony"}', (string) $this->client->getResponse()->getContent());
    }

    public function testNestedPropTypesOnFullVisitEmitDotPathMetadata(): void
    {
        $this->client->request('GET', '/test/nested-props', [], [], ['HTTP_X_INERTIA' => 'true']);

        $data = $this->decodeJsonResponse();
        self::assertSame([['id' => 1]], $data['props']['feed']['posts']);
        self::assertArrayNotHasKey('comments', $data['props']['feed']);
        self::assertSame(['feed.posts'], $data['mergeProps']);
        self::assertSame(['feed.posts.id'], $data['matchPropsOn']);
        self::assertSame(['default' => ['feed.comments']], $data['deferredProps']);
    }

    public function testDotPathPartialReloadReturnsOnlyRequestedSubtree(): void
    {
        $this->client->request('GET', '/test/nested-props', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
            'HTTP_X_INERTIA_PARTIAL_DATA' => 'auth.user',
        ]);

        self::assertResponseIsSuccessful();
        $props = $this->decodeJsonResponse()['props'];
        self::assertSame(['auth', 'errors'], array_keys($props));
        self::assertSame(['user' => ['name' => 'Tony']], $props['auth']);
    }

    public function testDotPathPartialExceptRemovesOnlyThatSubtree(): void
    {
        $this->client->request('GET', '/test/nested-props', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
            'HTTP_X_INERTIA_PARTIAL_EXCEPT' => 'auth.permissions',
        ]);

        self::assertResponseIsSuccessful();
        $props = $this->decodeJsonResponse()['props'];
        self::assertSame(['user' => ['name' => 'Tony']], $props['auth']);
        self::assertSame('o', $props['other']);
    }

    public function testDeferredFetchOfNestedPropResolvesIt(): void
    {
        $this->client->request('GET', '/test/nested-props', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
            'HTTP_X_INERTIA_PARTIAL_DATA' => 'feed.comments',
        ]);

        self::assertResponseIsSuccessful();
        $data = $this->decodeJsonResponse();
        self::assertSame(['comments' => ['c1']], $data['props']['feed']);
        self::assertArrayNotHasKey('deferredProps', $data);
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
