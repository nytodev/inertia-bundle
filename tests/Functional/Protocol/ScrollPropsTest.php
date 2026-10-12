<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional\Protocol;

use Nytodev\InertiaBundle\Tests\Functional\FunctionalTestCase;

final class ScrollPropsTest extends FunctionalTestCase
{
    // --- XHR full render ---

    public function testScrollPropsOnXhrFullRenderAppearsInPageObjectWithMetadata(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test/scroll-props', [], [], [
            'HTTP_X-Inertia' => 'true',
            'HTTP_X-Inertia-Version' => '',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('scrollProps', $data);
        $this->assertSame('page', $data['scrollProps']['posts']['pageName']);
        $this->assertSame(2, $data['scrollProps']['posts']['nextPage']);
        $this->assertNull($data['scrollProps']['posts']['previousPage']);
        $this->assertNull($data['scrollProps']['posts']['currentPage']);
        $this->assertFalse($data['scrollProps']['posts']['reset']);
    }

    public function testScrollPropsOnXhrFullRenderPropKeyAlsoInMergeProps(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test/scroll-props', [], [], [
            'HTTP_X-Inertia' => 'true',
            'HTTP_X-Inertia-Version' => '',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('mergeProps', $data);
        $this->assertContains('posts', $data['mergeProps']);
    }

    public function testScrollPropsWhenNoScrollPropAbsentFromPageObject(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test/merge', [], [], [
            'HTTP_X-Inertia' => 'true',
            'HTTP_X-Inertia-Version' => '',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertArrayNotHasKey('scrollProps', $data);
    }

    // --- HTML first visit ---

    public function testScrollPropsOnHtmlFirstVisitAppearsInScriptTag(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test/scroll-props');

        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $this->assertStringContainsString('<script data-page="app" type="application/json">', $html);

        $matched = preg_match('/<script[^>]+type="application\/json"[^>]*>([^<]+)<\/script>/s', $html, $matches);
        $this->assertSame(1, $matched, '<script type="application/json"> tag not found in HTML');
        $json = $matches[1];
        $page = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('scrollProps', $page);
        $this->assertSame('page', $page['scrollProps']['posts']['pageName']);
        $this->assertSame(2, $page['scrollProps']['posts']['nextPage']);
        $this->assertNull($page['scrollProps']['posts']['previousPage']);
        $this->assertNull($page['scrollProps']['posts']['currentPage']);
        $this->assertFalse($page['scrollProps']['posts']['reset']);
    }

    // --- Partial reload filters ---

    public function testScrollPropsWhenPropSurvivesPartialReloadScrollPropsPresent(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test/scroll-props', [], [], [
            'HTTP_X-Inertia' => 'true',
            'HTTP_X-Inertia-Version' => '',
            'HTTP_X-Inertia-Partial-Data' => 'posts',
            'HTTP_X-Inertia-Partial-Component' => 'TestComponent',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('scrollProps', $data);
        $this->assertArrayHasKey('posts', $data['scrollProps']);
        $this->assertContains('posts', $data['mergeProps']);
    }

    public function testScrollPropsWhenPropNotInOnlyListScrollPropsAbsent(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test/scroll-props', [], [], [
            'HTTP_X-Inertia' => 'true',
            'HTTP_X-Inertia-Version' => '',
            'HTTP_X-Inertia-Partial-Data' => 'errors',
            'HTTP_X-Inertia-Partial-Component' => 'TestComponent',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertArrayNotHasKey('scrollProps', $data);
        $this->assertArrayNotHasKey('mergeProps', $data);
        $this->assertArrayHasKey('errors', $data['props']);
    }

    public function testScrollPropsWhenPropExcludedByPartialReloadScrollPropsAbsent(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test/scroll-props', [], [], [
            'HTTP_X-Inertia' => 'true',
            'HTTP_X-Inertia-Version' => '',
            'HTTP_X-Inertia-Partial-Except' => 'posts',
            'HTTP_X-Inertia-Partial-Component' => 'TestComponent',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertArrayNotHasKey('scrollProps', $data);
        $this->assertArrayNotHasKey('mergeProps', $data);
    }

    // --- Prepend mode ---

    public function testScrollPropsWhenPrependTrueKeyInPrependPropsAndScrollPropsPresent(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test/scroll-props-prepend', [], [], [
            'HTTP_X-Inertia' => 'true',
            'HTTP_X-Inertia-Version' => '',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('prependProps', $data);
        $this->assertContains('posts', $data['prependProps']);
        $this->assertArrayNotHasKey('mergeProps', $data);
        $this->assertArrayHasKey('scrollProps', $data);
        $this->assertArrayHasKey('posts', $data['scrollProps']);
    }

    // --- X-Inertia-Infinite-Scroll-Merge-Intent ---

    public function testScrollMergeIntentPrependStaticAppendKeyMovesToPrependProps(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test/scroll-props-intent', [], [], [
            'HTTP_X-Inertia' => 'true',
            'HTTP_X-Inertia-Version' => '',
            'HTTP_X-Inertia-Infinite-Scroll-Merge-Intent' => 'prepend',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('prependProps', $data);
        $this->assertContains('posts', $data['prependProps']);
        $this->assertArrayNotHasKey('mergeProps', $data);
        $this->assertArrayHasKey('scrollProps', $data);
    }

    public function testScrollMergeIntentAppendStaticPrependKeyMovesToMergeProps(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test/scroll-props-intent-prepend', [], [], [
            'HTTP_X-Inertia' => 'true',
            'HTTP_X-Inertia-Version' => '',
            'HTTP_X-Inertia-Infinite-Scroll-Merge-Intent' => 'append',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('mergeProps', $data);
        $this->assertContains('posts', $data['mergeProps']);
        $this->assertArrayNotHasKey('prependProps', $data);
        $this->assertArrayHasKey('scrollProps', $data);
    }

    public function testScrollMergeIntentAbsentStaticPrependKeyStaysInPrependProps(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test/scroll-props-intent-prepend', [], [], [
            'HTTP_X-Inertia' => 'true',
            'HTTP_X-Inertia-Version' => '',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('prependProps', $data);
        $this->assertContains('posts', $data['prependProps']);
        $this->assertArrayNotHasKey('mergeProps', $data);
    }

    public function testScrollMergeIntentInvalidValueTreatedAsAppend(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test/scroll-props-intent', [], [], [
            'HTTP_X-Inertia' => 'true',
            'HTTP_X-Inertia-Version' => '',
            'HTTP_X-Inertia-Infinite-Scroll-Merge-Intent' => 'sideways',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('mergeProps', $data);
        $this->assertContains('posts', $data['mergeProps']);
        $this->assertArrayNotHasKey('prependProps', $data);
    }

    // --- Full metadata ---

    public function testScrollPropsWithAllMetadataFieldsAllAppearsInPageObject(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test/scroll-props-full', [], [], [
            'HTTP_X-Inertia' => 'true',
            'HTTP_X-Inertia-Version' => '',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('scrollProps', $data);
        $scrollMeta = $data['scrollProps']['posts'];
        $this->assertSame('page', $scrollMeta['pageName']);
        $this->assertSame(3, $scrollMeta['nextPage']);
        $this->assertSame(1, $scrollMeta['previousPage']);
        $this->assertSame(2, $scrollMeta['currentPage']);
    }

    // --- ScrollProp deferrable ---

    public function testScrollDeferOnInitialLoadKeyInDeferredPropsAndMergePropsNotScrollProps(): void
    {
        // Deferred ScrollProp on initial load: key in deferredProps and mergeProps,
        // but NOT in scrollProps (metadata only appears when value is resolved).
        $client = self::createClient();
        $client->request('GET', '/test/scroll-defer', [], [], ['HTTP_X-Inertia' => 'true']);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('deferredProps', $data);
        $this->assertContains('posts', $data['deferredProps']['default'] ?? []);

        $this->assertArrayHasKey('mergeProps', $data);
        $this->assertContains('posts', $data['mergeProps']);

        $this->assertArrayNotHasKey('scrollProps', $data);
        $this->assertArrayNotHasKey('posts', $data['props'] ?? []);
    }

    public function testScrollDeferOnDeferredXhrValueResolvedScrollPropsAndMergePropsPresent(): void
    {
        // When the client fetches the deferred scroll prop: value resolved, scrollProps and mergeProps present.
        $client = self::createClient();
        $client->request('GET', '/test/scroll-defer', [], [], [
            'HTTP_X-Inertia' => 'true',
            'HTTP_X-Inertia-Partial-Data' => 'posts',
            'HTTP_X-Inertia-Partial-Component' => 'TestComponent',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('posts', $data['props'] ?? []);
        $this->assertArrayHasKey('scrollProps', $data);
        $this->assertArrayHasKey('posts', $data['scrollProps']);
        $this->assertSame(2, $data['scrollProps']['posts']['nextPage']);
        $this->assertArrayHasKey('mergeProps', $data);
        $this->assertContains('posts', $data['mergeProps']);
    }

    public function testScrollDeferCustomGroupAppearsInCorrectDeferGroup(): void
    {
        $client = self::createClient();
        $client->request('GET', '/test/scroll-defer-group', [], [], ['HTTP_X-Inertia' => 'true']);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('deferredProps', $data);
        $this->assertArrayHasKey('sidebar', $data['deferredProps']);
        $this->assertContains('posts', $data['deferredProps']['sidebar']);
        $this->assertArrayNotHasKey('default', $data['deferredProps']);
    }
}
