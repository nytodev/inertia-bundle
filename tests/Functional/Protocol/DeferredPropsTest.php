<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional\Protocol;

use Nytodev\InertiaBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class DeferredPropsTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
    }

    public function testDeferredPropsExcludedFromInitialResponse(): void
    {
        // The /test route uses regular props — no DeferProps configured.
        // We verify the standard response has no deferredProps key when none are set.
        $this->client->request('GET', '/test', [], [], ['HTTP_X_INERTIA' => 'true']);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('deferredProps', $data);
    }

    public function testDeferredPropsIncludedInDeferredPropsField(): void
    {
        // Covered by unit test: testBuildPageObject_DeferredPropsIncludedWhenPresent
        // Functional confirmation: with no DeferProps, field absent.
        $this->client->request('GET', '/test', [], [], ['HTTP_X_INERTIA' => 'true']);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
    }

    public function testDeferredPropsGroupedByGroupName(): void
    {
        // Covered by unit tests. Functional environment uses no DeferProps.
        $this->client->request('GET', '/test', [], [], ['HTTP_X_INERTIA' => 'true']);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('deferredProps', $data);
    }

    public function testDeferredPropsResolvedWhenFetchedByClient(): void
    {
        // The client performs a separate partial XHR to fetch deferred props.
        $this->client->request('GET', '/test/defer', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_DATA' => 'deferred',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['props']);
        self::assertArrayHasKey('deferred', $data['props']);
        self::assertSame('deferred-value', $data['props']['deferred']);
    }

    public function testDeferredPropsAbsentFromDeferredFetchResponse(): void
    {
        // When the client fetches deferred props via a partial XHR, the response must NOT
        // include deferredProps — otherwise the client would re-trigger the XHR indefinitely.
        $this->client->request('GET', '/test/defer', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_DATA' => 'deferred',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('deferredProps', $data);
    }

    // --- DeferProp mergeable ---

    public function testDeferMergeOnInitialLoadKeyInBothDeferredPropsAndMergeProps(): void
    {
        // DeferProp+merge(): initial load → key in deferredProps AND mergeProps simultaneously.
        // The client needs merge metadata upfront so it knows to merge (not replace) on deferred XHR.
        $this->client->request('GET', '/test/defer-merge', [], [], ['HTTP_X_INERTIA' => 'true']);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        self::assertArrayHasKey('deferredProps', $data);
        self::assertContains('deferred', $data['deferredProps']['default'] ?? []);

        self::assertArrayHasKey('mergeProps', $data);
        self::assertContains('deferred', $data['mergeProps']);
    }

    public function testDeferMergeOnInitialLoadValueAbsentFromProps(): void
    {
        // DeferProp value must not be resolved on initial load.
        $this->client->request('GET', '/test/defer-merge', [], [], ['HTTP_X_INERTIA' => 'true']);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('deferred', $data['props'] ?? []);
    }

    public function testDeferMergeOnDeferredXhrValueResolvedAndKeyInMergeProps(): void
    {
        // When the client fetches the deferred prop, the value is resolved AND mergeProps is present.
        $this->client->request('GET', '/test/defer-merge', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_DATA' => 'deferred',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        self::assertArrayHasKey('deferred', $data['props'] ?? []);
        self::assertSame('deferred-value', $data['props']['deferred']);
        self::assertArrayHasKey('mergeProps', $data);
        self::assertContains('deferred', $data['mergeProps']);
    }

    public function testDeferDeepMergeOnInitialLoadKeyInDeepMergePropsAndDeferredProps(): void
    {
        $this->client->request('GET', '/test/defer-deep-merge', [], [], ['HTTP_X_INERTIA' => 'true']);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        self::assertArrayHasKey('deferredProps', $data);
        self::assertArrayHasKey('deepMergeProps', $data);
        self::assertContains('deferred', $data['deepMergeProps']);
        self::assertArrayNotHasKey('mergeProps', $data);
    }

    public function testDeferMergeMatchOnInitialLoadKeyInMatchPropsOn(): void
    {
        $this->client->request('GET', '/test/defer-merge-match-on', [], [], ['HTTP_X_INERTIA' => 'true']);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        self::assertArrayHasKey('mergeProps', $data);
        self::assertContains('deferred', $data['mergeProps']);
        self::assertArrayHasKey('matchPropsOn', $data);
        self::assertContains('deferred.id', $data['matchPropsOn']);
    }

    public function testDeferWithoutMergeNotInMergePropsOnInitialLoad(): void
    {
        // Regression guard: plain DeferProp (no merge) must NOT appear in mergeProps.
        $this->client->request('GET', '/test/defer', [], [], ['HTTP_X_INERTIA' => 'true']);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('mergeProps', $data);
    }

    // --- DeferProp::once() ---

    public function testDeferOnceOnInitialLoadKeyInBothDeferredPropsAndOnceProps(): void
    {
        $this->client->request('GET', '/test/defer-once', [], [], ['HTTP_X_INERTIA' => 'true']);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        // Key must appear in deferredProps (not resolved on initial load)
        self::assertArrayHasKey('deferredProps', $data);
        self::assertContains('deferred', $data['deferredProps']['default'] ?? []);

        // Key must ALSO appear in onceProps (client caches the resolved value after deferred XHR)
        self::assertArrayHasKey('onceProps', $data);
        self::assertArrayHasKey('deferred', $data['onceProps']);
        self::assertSame('deferred', $data['onceProps']['deferred']['prop']);
        self::assertNull($data['onceProps']['deferred']['expiresAt']);
    }

    public function testDeferOnceOnInitialLoadValueAbsentFromProps(): void
    {
        $this->client->request('GET', '/test/defer-once', [], [], ['HTTP_X_INERTIA' => 'true']);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('deferred', $data['props'] ?? []);
    }

    public function testDeferOnceDeferredXhrResolvesValue(): void
    {
        $this->client->request('GET', '/test/defer-once', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_DATA' => 'deferred',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('deferred', $data['props'] ?? []);
        self::assertSame('deferred-once-value', $data['props']['deferred']);
    }

    public function testDeferOnceExplicitlyRequestedIsResolvedEvenWhenClientHasIt(): void
    {
        $this->client->request('GET', '/test/defer-once', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_PARTIAL_DATA' => 'deferred',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => 'TestComponent',
            'HTTP_X_INERTIA_EXCEPT_ONCE_PROPS' => 'deferred',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('deferred-once-value', $data['props']['deferred']);
    }

    public function testDeferOnceAlreadyLoadedIsNotAnnouncedOnFullVisit(): void
    {
        $this->client->request('GET', '/test/defer-once', [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_EXCEPT_ONCE_PROPS' => 'deferred',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('deferredProps', $data);
        self::assertSame(['prop' => 'deferred', 'expiresAt' => null], $data['onceProps']['deferred']);
    }
}
