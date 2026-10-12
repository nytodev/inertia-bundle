<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional\Protocol;

use Nytodev\InertiaBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class PartialReloadTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
    }

    public function testPartialReloadWithPartialDataHeaderReturnsOnlyRequestedProps(): void
    {
        $this->client->request('GET', '/test/partial', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_DATA' => 'foo',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['props']);
        self::assertArrayHasKey('foo', $data['props']);
        self::assertArrayNotHasKey('baz', $data['props']);
    }

    public function testPartialReloadWithPartialExceptHeaderExcludesListedProps(): void
    {
        $this->client->request('GET', '/test/partial', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_EXCEPT' => 'baz',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['props']);
        self::assertArrayHasKey('foo', $data['props']);
        self::assertArrayNotHasKey('baz', $data['props']);
    }

    public function testPartialReloadErrorsPropAlwaysIncluded(): void
    {
        $this->client->request('GET', '/test/partial', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_DATA' => 'foo',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['props']);
        self::assertArrayHasKey('errors', $data['props']);
    }

    public function testPartialReloadOptionalPropResolvedWhenRequested(): void
    {
        $this->client->request('GET', '/test/optional', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_DATA' => 'lazy',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['props']);
        self::assertArrayHasKey('lazy', $data['props']);
        self::assertSame('lazy-value', $data['props']['lazy']);
    }

    public function testPartialReloadOptionalPropSkippedWhenNotRequested(): void
    {
        $this->client->request('GET', '/test/optional', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['props']);
        self::assertArrayNotHasKey('lazy', $data['props']);
    }

    public function testPartialReloadWithMismatchedComponentReturnsFullResponse(): void
    {
        $this->client->request('GET', '/test/partial', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_DATA' => 'foo',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'WrongComponent',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['props']);
        // Partial filtering must NOT be applied — both props must be present
        self::assertArrayHasKey('foo', $data['props']);
        self::assertArrayHasKey('baz', $data['props']);
    }

    public function testPartialReloadExceptWinsOverOnlyWhenBothPresent(): void
    {
        $this->client->request('GET', '/test/partial', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_DATA' => 'foo,baz',
            'HTTP_X_INERTIA_PARTIAL_EXCEPT' => 'baz',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['props']);
        self::assertArrayHasKey('foo', $data['props']);
        self::assertArrayNotHasKey('baz', $data['props']);
    }

    // -------------------------------------------------------------------------
    // BUG 4 — OptionalProp must NOT be resolved when only X-Inertia-Partial-Except is set
    // -------------------------------------------------------------------------

    public function testPartialReloadOptionalPropExceptOnlyHeaderOptionalPropNotResolved(): void
    {
        // Only X-Inertia-Partial-Except is sent (no X-Inertia-Partial-Data).
        // OptionalProp must NOT appear because it was never explicitly requested via $only.
        $this->client->request('GET', '/test/optional', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_EXCEPT' => 'eager',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['props']);
        // 'eager' was excluded; 'lazy' was never requested — must be absent.
        self::assertArrayNotHasKey('lazy', $data['props']);
        self::assertArrayNotHasKey('eager', $data['props']);
    }

    // -------------------------------------------------------------------------
    // AlwaysProp — always included regardless of partial reload filters
    // -------------------------------------------------------------------------

    public function testAlwaysPropIncludedOnFullRender(): void
    {
        $this->client->request('GET', '/test/always', [], [], [
            'HTTP_X_INERTIA' => 'true',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('always-value', $data['props']['always']);
        self::assertSame('regular-value', $data['props']['regular']);
    }

    public function testAlwaysPropIncludedWhenNotInPartialData(): void
    {
        // Partial reload requests only 'regular' — 'always' must still appear.
        $this->client->request('GET', '/test/always', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_DATA' => 'regular',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('always', $data['props']);
        self::assertSame('always-value', $data['props']['always']);
        self::assertArrayHasKey('regular', $data['props']);
    }

    public function testAlwaysPropIncludedEvenWhenInExcept(): void
    {
        // Partial reload explicitly excludes 'always' — AlwaysProp must bypass $except.
        $this->client->request('GET', '/test/always', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_EXCEPT' => 'always',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('always', $data['props']);
        self::assertSame('always-value', $data['props']['always']);
    }

    // -------------------------------------------------------------------------
    // OnceProp — filtered by $only partial-data list
    // -------------------------------------------------------------------------

    public function testOncePropExcludedByOnlyFilterAbsentFromResponse(): void
    {
        // Partial reload requests only 'regular' — 'plans' (OnceProp) must be absent.
        $this->client->request('GET', '/test/once-prop', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_DATA' => 'regular',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data['props']);
        self::assertArrayHasKey('regular', $data['props']);
        self::assertArrayNotHasKey('plans', $data['props']);
    }

    // -------------------------------------------------------------------------
    // optional()->once() — lazy + once caching combined
    // -------------------------------------------------------------------------

    public function testOptionalOnceAbsentFromFullRenderPropsButPresentInOnceProps(): void
    {
        $this->client->request('GET', '/test/optional-once', [], [], ['HTTP_X_INERTIA' => 'true']);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        // Absent from props (lazy — never resolved on full render)
        self::assertArrayNotHasKey('permissions', $data['props'] ?? []);

        // But present in onceProps so the client knows to cache after first partial resolve
        self::assertArrayHasKey('onceProps', $data);
        self::assertArrayHasKey('permissions', $data['onceProps']);
        self::assertSame('permissions', $data['onceProps']['permissions']['prop']);
        self::assertNull($data['onceProps']['permissions']['expiresAt']);
    }

    public function testOptionalOnceResolvedWhenExplicitlyRequested(): void
    {
        $this->client->request('GET', '/test/optional-once', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_DATA' => 'permissions',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('permissions', $data['props'] ?? []);
        self::assertSame(['read', 'write'], $data['props']['permissions']);
    }

    public function testOptionalOnceSkippedWhenClientSendsExceptOnceProps(): void
    {
        // Client has cached the permissions — sends X-Inertia-Except-Once-Props.
        // Server must NOT re-resolve even if the key is in X-Inertia-Partial-Data.
        $this->client->request('GET', '/test/optional-once', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_DATA' => 'permissions',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
            'HTTP_X_INERTIA_EXCEPT_ONCE_PROPS' => 'permissions',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('permissions', $data['props'] ?? []);
    }

    // -------------------------------------------------------------------------
    // BUG 3 — MergeProp keys must not appear in mergeProps after $except filtering
    // -------------------------------------------------------------------------

    public function testPartialReloadMergePropExcludedViaExceptAbsentFromMergePropsMetadata(): void
    {
        // 'merge_a' is in $only, 'merge_b' is in $except — merge_b must not appear in mergeProps.
        $this->client->request('GET', '/test/merge', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_DATA' => 'merge_a,merge_b',
            'HTTP_X_INERTIA_PARTIAL_EXCEPT' => 'merge_b',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        // merge_a survived — must be in mergeProps.
        self::assertContains('merge_a', $data['mergeProps'] ?? []);
        // merge_b was filtered — must NOT be in mergeProps.
        self::assertNotContains('merge_b', $data['mergeProps'] ?? []);
    }

    public function testPartialReloadDeferredFetchNeverCallsUnrequestedClosure(): void
    {
        $this->client->request('GET', '/test/closure-on-partial-reload', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_DATA' => 'reviews',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(['a'], $data['props']['reviews']);
        self::assertArrayNotHasKey('expensive', $data['props']);
    }

    public function testPartialReloadExceptNeverCallsExcludedClosure(): void
    {
        $this->client->request('GET', '/test/closure-on-partial-reload', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_EXCEPT' => 'expensive',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('x', $data['props']['cheap']);
        self::assertArrayNotHasKey('expensive', $data['props']);
    }
}
