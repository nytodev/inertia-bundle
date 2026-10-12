<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional\Protocol;

use Nytodev\InertiaBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * router.reload({ reset: ['key'] }) must replace the client value, not merge into it:
 * the server omits reset keys from merge metadata (inertia-laravel PropsResolver).
 * The v3 client appends reset keys to X-Inertia-Partial-Data — mirrored here.
 */
final class ResetPropsTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
    }

    public function testResetReloadOnMergePropOmitsKeyFromMergePropsAndReturnsValue(): void
    {
        $this->client->request('GET', '/test/merge', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
            'HTTP_X_INERTIA_PARTIAL_DATA' => 'merge_a',
            'HTTP_X_INERTIA_RESET' => 'merge_a',
        ]);

        self::assertResponseIsSuccessful();
        $data = $this->decodeJsonResponse();
        self::assertSame([1, 2], $data['props']['merge_a']);
        self::assertArrayNotHasKey('mergeProps', $data);
    }

    public function testMergeReloadWithoutResetHeaderKeepsKeyInMergeProps(): void
    {
        $this->client->request('GET', '/test/merge', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
            'HTTP_X_INERTIA_PARTIAL_DATA' => 'merge_a',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(['merge_a'], $this->decodeJsonResponse()['mergeProps']);
    }

    public function testResetReloadOnScrollPropOmitsKeyFromMergePropsAndFlagsReset(): void
    {
        $this->client->request('GET', '/test/scroll-props', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
            'HTTP_X_INERTIA_PARTIAL_DATA' => 'posts',
            'HTTP_X_INERTIA_RESET' => 'posts',
        ]);

        self::assertResponseIsSuccessful();
        $data = $this->decodeJsonResponse();
        self::assertArrayNotHasKey('mergeProps', $data);
        self::assertTrue($data['scrollProps']['posts']['reset']);
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
