<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Unit\Response;

use Nytodev\InertiaBundle\Props\DeferProp;
use Nytodev\InertiaBundle\Props\MergeProp;
use Nytodev\InertiaBundle\Props\OnceProp;
use Nytodev\InertiaBundle\Props\ScrollProp;
use Nytodev\InertiaBundle\Response\InertiaResponse;
use Nytodev\InertiaBundle\Ssr\NullSsrGateway;
use Nytodev\InertiaBundle\Ssr\SsrState;
use Nytodev\InertiaBundle\Twig\InertiaTwigExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class InertiaResponseTest extends TestCase
{
    private InertiaResponse $response;

    protected function setUp(): void
    {
        $twig = new Environment(new ArrayLoader([
            'base.html.twig' => '<body>{{ inertia(page) }}</body>',
        ]));
        $twig->addExtension(new InertiaTwigExtension(new SsrState(new NullSsrGateway())));
        $this->response = new InertiaResponse($twig, 'base.html.twig');
    }

    // -------------------------------------------------------------------------
    // buildPageObject()
    // -------------------------------------------------------------------------

    public function testBuildPageObjectOmitsClearHistoryAndEncryptHistoryWhenFalse(): void
    {
        $page = $this->response->buildPageObject('Home', [], '/home', null, false, false);
        self::assertArrayNotHasKey('clearHistory', $page);
        self::assertArrayNotHasKey('encryptHistory', $page);
    }

    public function testBuildPageObjectIncludesClearHistoryWhenTrue(): void
    {
        $page = $this->response->buildPageObject('Home', [], '/home', null, true, false);
        self::assertArrayHasKey('clearHistory', $page);
        self::assertTrue($page['clearHistory']);
        self::assertArrayNotHasKey('encryptHistory', $page);
    }

    public function testBuildPageObjectIncludesEncryptHistoryWhenTrue(): void
    {
        $page = $this->response->buildPageObject('Home', [], '/home', null, false, true);
        self::assertArrayNotHasKey('clearHistory', $page);
        self::assertArrayHasKey('encryptHistory', $page);
        self::assertTrue($page['encryptHistory']);
    }

    public function testBuildPageObjectIncludesBothFlagsWhenBothTrue(): void
    {
        $page = $this->response->buildPageObject('Home', [], '/home', null, true, true);
        self::assertArrayHasKey('clearHistory', $page);
        self::assertTrue($page['clearHistory']);
        self::assertArrayHasKey('encryptHistory', $page);
        self::assertTrue($page['encryptHistory']);
    }

    public function testBuildPageObjectContainsAllRequiredV3Fields(): void
    {
        $page = $this->response->buildPageObject('Home', ['foo' => 'bar'], '/home', 'v1', false, false);
        self::assertSame('Home', $page['component']);
        self::assertSame(['foo' => 'bar'], $page['props']);
        self::assertSame('/home', $page['url']);
        self::assertSame('v1', $page['version']);
    }

    public function testBuildPageObjectDeferredPropsOmittedWhenEmpty(): void
    {
        $page = $this->response->buildPageObject('Home', [], '/home', null, false, false);
        self::assertArrayNotHasKey('deferredProps', $page);
    }

    public function testBuildPageObjectDeferredPropsIncludedWhenPresent(): void
    {
        $page = $this->response->buildPageObject('Home', [], '/home', null, false, false, ['default' => ['users']]);
        self::assertSame(['default' => ['users']], $page['deferredProps']);
    }

    public function testBuildPageObjectJsonEncodesWithXssProtectionFlags(): void
    {
        // This is tested via renderInertia in Twig extension; here we verify the page object
        // has the component key so the extension can encode it safely.
        $page = $this->response->buildPageObject('<script>alert(1)</script>', [], '/home', null, false, false);
        self::assertSame('<script>alert(1)</script>', $page['component']);
    }

    // -------------------------------------------------------------------------
    // build()
    // -------------------------------------------------------------------------

    public function testBuildWithFirstVisitRequestReturnsHtmlResponse(): void
    {
        $loader = new ArrayLoader(['base.html.twig' => '<div>{{ page|json_encode }}</div>']);
        $twig = new Environment($loader);
        $response = new InertiaResponse($twig, 'base.html.twig');

        $request = Request::create('/home');
        $result = $response->build('Home', ['foo' => 'bar'], '/home', null, $request);

        self::assertSame(200, $result->getStatusCode());
        self::assertStringContainsString('Home', (string) $result->getContent());
    }

    public function testBuildWithXhrRequestReturnsJsonResponse(): void
    {
        $twig = new Environment(new ArrayLoader([]));
        $response = new InertiaResponse($twig, 'base.html.twig');

        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');

        $result = $response->build('Home', ['foo' => 'bar'], '/home', null, $request);

        self::assertSame(200, $result->getStatusCode());
        self::assertSame('true', $result->headers->get('X-Inertia'));
        self::assertStringContainsString('X-Inertia', $result->headers->get('Vary') ?? '');
        self::assertStringContainsString('application/json', $result->headers->get('Content-Type') ?? '');

        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('Home', $data['component']);
    }

    public function testBuildWithXhrRequestPageObjectHasAllV2Fields(): void
    {
        $twig = new Environment(new ArrayLoader([]));
        $response = new InertiaResponse($twig, 'base.html.twig');

        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');

        $result = $response->build('Home', [], '/home', null, $request);
        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);

        self::assertArrayHasKey('component', $data);
        self::assertArrayHasKey('props', $data);
        self::assertArrayHasKey('url', $data);
        self::assertArrayHasKey('version', $data);
        self::assertArrayNotHasKey('clearHistory', $data);
        self::assertArrayNotHasKey('encryptHistory', $data);
    }

    public function testBuildPropsAlwaysContainsErrorsDefaultWhenNotProvidedByController(): void
    {
        $twig = new Environment(new ArrayLoader([]));
        $response = new InertiaResponse($twig, 'base.html.twig');

        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');

        $result = $response->build('Home', [], '/home', null, $request);
        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);

        self::assertArrayHasKey('errors', $data['props']);
        self::assertSame([], $data['props']['errors']);
    }

    public function testBuildWithPartialReloadHeadersReturnsOnlyRequestedProps(): void
    {
        $twig = new Environment(new ArrayLoader([]));
        $response = new InertiaResponse($twig, 'base.html.twig');

        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Inertia-Partial-Data', 'foo');
        $request->headers->set('X-Inertia-Partial-Component', 'Home');

        $result = $response->build('Home', ['foo' => 'bar', 'baz' => 'qux'], '/home', null, $request);
        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['props']);

        self::assertArrayHasKey('foo', $data['props']);
        self::assertArrayNotHasKey('baz', $data['props']);
    }

    public function testBuildHtmlFirstVisitWithInertiaExtensionRendersDataPageDiv(): void
    {
        $request = Request::create('/home');

        $result = $this->response->build('Home', ['name' => 'Tony'], '/home', null, $request);

        self::assertSame(200, $result->getStatusCode());
        self::assertStringContainsString('data-page', (string) $result->getContent());
        self::assertStringContainsString('Home', (string) $result->getContent());
    }

    public function testBuildWithDeferPropIncludesDeferredGroupInPageObject(): void
    {
        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');

        $result = $this->response->build('Home', [
            'users' => new DeferProp(static fn () => ['Alice']),
            'posts' => new DeferProp(static fn () => ['B'], 'sidebar'),
        ], '/home', null, $request);

        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('deferredProps', $data);
        self::assertContains('users', $data['deferredProps']['default']);
        self::assertContains('posts', $data['deferredProps']['sidebar']);
    }

    public function testBuildWithAllMergePropTypesTracksEachCategoryInPageObject(): void
    {
        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');

        $result = $this->response->build('Home', [
            'regular' => new MergeProp(static fn () => [1, 2]),
            'prepended' => new MergeProp(static fn () => [0], prepend: true),
            'deep' => new MergeProp(static fn () => ['k' => 'v'], deep: true),
        ], '/home', null, $request);

        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);
        self::assertContains('regular', $data['mergeProps']);
        self::assertContains('prepended', $data['prependProps']);
        self::assertContains('deep', $data['deepMergeProps']);
    }

    public function testBuildPageObjectWithNonEmptyMergeArraysIncludesAllKeys(): void
    {
        $page = $this->response->buildPageObject(
            'Home', [], '/home', null, false, false,
            [], ['a'], ['b'], ['c'],
        );

        self::assertSame(['a'], $page['mergeProps']);
        self::assertSame(['b'], $page['prependProps']);
        self::assertSame(['c'], $page['deepMergeProps']);
    }

    public function testBuildWithSpacesInPartialDataHeaderParsesAndTrimsCorrectly(): void
    {
        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Inertia-Partial-Data', 'foo , bar , baz');
        $request->headers->set('X-Inertia-Partial-Component', 'Home');

        $result = $this->response->build('Home', [
            'foo' => 1, 'bar' => 2, 'baz' => 3, 'qux' => 4,
        ], '/home', null, $request);

        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['props']);
        self::assertArrayHasKey('foo', $data['props']);
        self::assertArrayHasKey('bar', $data['props']);
        self::assertArrayHasKey('baz', $data['props']);
        self::assertArrayNotHasKey('qux', $data['props']);
    }

    // -------------------------------------------------------------------------
    // BUG 3 — MergeProp keys must not appear in merge arrays when filtered by $except
    // -------------------------------------------------------------------------

    public function testBuildWithMergePropExcludedViaExceptKeyAbsentFromMergeProps(): void
    {
        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Inertia-Partial-Data', 'regular,excluded');
        $request->headers->set('X-Inertia-Partial-Except', 'excluded');
        $request->headers->set('X-Inertia-Partial-Component', 'Home');

        $result = $this->response->build('Home', [
            'regular' => new MergeProp(static fn () => [1, 2]),
            'excluded' => new MergeProp(static fn () => [3, 4]),
        ], '/home', null, $request);

        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);

        // 'regular' survived — must appear in mergeProps.
        self::assertContains('regular', $data['mergeProps']);
        // 'excluded' was filtered by $except — must NOT appear in mergeProps.
        self::assertNotContains('excluded', $data['mergeProps'] ?? []);
    }

    // -------------------------------------------------------------------------
    // X-Inertia-Reset → reset flag inside scrollProps metadata (matching inertia-laravel)
    // -------------------------------------------------------------------------

    public function testBuildWithResetHeaderEmitsResetFlagInScrollProps(): void
    {
        $twig = new Environment(new ArrayLoader([]));
        $response = new InertiaResponse($twig, 'base.html.twig');

        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Inertia-Partial-Data', 'posts');
        $request->headers->set('X-Inertia-Partial-Component', 'Home');
        $request->headers->set('X-Inertia-Reset', 'posts');

        $result = $response->build('Home', ['posts' => new ScrollProp(static fn () => [1, 2, 3])], '/home', null, $request);
        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('resetProps', $data);
        self::assertArrayHasKey('scrollProps', $data);
        self::assertTrue($data['scrollProps']['posts']['reset']);
    }

    public function testBuildWithoutResetHeaderEmitsFalseResetFlagInScrollProps(): void
    {
        $twig = new Environment(new ArrayLoader([]));
        $response = new InertiaResponse($twig, 'base.html.twig');

        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');

        $result = $response->build('Home', ['posts' => new ScrollProp(static fn () => [1, 2, 3])], '/home', null, $request);
        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('resetProps', $data);
        self::assertArrayHasKey('scrollProps', $data);
        self::assertFalse($data['scrollProps']['posts']['reset']);
    }

    // -------------------------------------------------------------------------
    // OnceProp → onceProps metadata in page object
    // -------------------------------------------------------------------------

    public function testBuildWithOncePropsEmitsOncePropsMetadataInPageObject(): void
    {
        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');

        $result = $this->response->build('Home', [
            'plans' => new OnceProp(static fn () => ['basic', 'pro']),
        ], '/home', null, $request);

        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('onceProps', $data);
        self::assertArrayHasKey('plans', $data['onceProps']);
        self::assertSame('plans', $data['onceProps']['plans']['prop']);
        self::assertNull($data['onceProps']['plans']['expiresAt']);
    }

    public function testBuildWithoutOncePropsOmitsOncePropsFromPageObject(): void
    {
        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');

        $result = $this->response->build('Home', ['foo' => 'bar'], '/home', null, $request);
        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('onceProps', $data);
    }

    public function testBuildOncePropsMetadataAlwaysPresentEvenWhenSkippedByExceptOnce(): void
    {
        // When X-Inertia-Except-Once-Props skips a prop, the value must be absent from props
        // but the metadata must still be present in onceProps so the client can reinject from cache.
        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Inertia-Except-Once-Props', 'plans');

        $result = $this->response->build('Home', [
            'plans' => new OnceProp(static fn () => ['basic', 'pro']),
        ], '/home', null, $request);

        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);
        // Metadata must be present so the client knows this is a once-prop and can reinject from cache.
        self::assertArrayHasKey('onceProps', $data);
        self::assertArrayHasKey('plans', $data['onceProps']);
        self::assertSame('plans', $data['onceProps']['plans']['prop']);
        // Value must be absent — the client uses its cached copy.
        self::assertArrayNotHasKey('plans', $data['props']);
    }

    public function testResolvePropsExceptOnceNotAppliedDuringPartialReload(): void
    {
        // During a partial reload, X-Inertia-Except-Once-Props must be ignored.
        // The client's "I already have this" signal is only relevant on full XHR visits.
        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Inertia-Partial-Component', 'Home');
        $request->headers->set('X-Inertia-Partial-Data', 'plans');
        $request->headers->set('X-Inertia-Except-Once-Props', 'plans');

        $result = $this->response->build('Home', [
            'plans' => new OnceProp(static fn () => ['basic', 'pro']),
        ], '/home', null, $request);

        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);
        // plans is in $only AND in $exceptOnce — $exceptOnce must not win on partials
        self::assertArrayHasKey('plans', $data['props']);
        self::assertSame(['basic', 'pro'], $data['props']['plans']);
    }

    public function testBuildOncePropsMetadataUsesAliasWhenAsModifierSet(): void
    {
        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');

        $result = $this->response->build('Home', [
            'plans' => (new OnceProp(static fn () => ['basic', 'pro']))->as('pricing'),
        ], '/home', null, $request);

        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);
        // outer key = alias, inner 'prop' = original key (matches inertia-laravel)
        self::assertArrayHasKey('pricing', $data['onceProps']);
        self::assertSame('plans', $data['onceProps']['pricing']['prop']);
    }

    public function testBuildOncePropsMetadataIncludesExpiresAtWhenUntilModifierSet(): void
    {
        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');

        $expiry = new \DateTimeImmutable('2030-06-01T12:00:00+00:00');
        $result = $this->response->build('Home', [
            'plans' => (new OnceProp(static fn () => ['basic', 'pro']))->until($expiry),
        ], '/home', null, $request);

        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);
        self::assertIsInt($data['onceProps']['plans']['expiresAt']);
        // Milliseconds: the client compares expiresAt with Date.now() (matches inertia-laravel).
        self::assertSame($expiry->getTimestamp() * 1000, $data['onceProps']['plans']['expiresAt']);
    }

    public function testOncePropExpiresAtWithTtlInSecondsIsMillisecondTimestampInTheFuture(): void
    {
        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');

        $before = time() * 1000;
        $result = $this->response->build('Home', [
            'plans' => (new OnceProp(static fn () => ['basic', 'pro']))->until(60),
        ], '/home', null, $request);
        $after = time() * 1000;

        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);
        $expiresAt = $data['onceProps']['plans']['expiresAt'];
        self::assertGreaterThanOrEqual($before + 60_000, $expiresAt);
        self::assertLessThanOrEqual($after + 60_000, $expiresAt);
    }

    public function testBuildOncePropsMetadataSetsFreshWhenFreshModifierSet(): void
    {
        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');

        $result = $this->response->build('Home', [
            'plans' => (new OnceProp(static fn () => ['basic', 'pro']))->fresh(),
        ], '/home', null, $request);

        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);
        self::assertTrue($data['onceProps']['plans']['fresh']);
    }

    public function testBuildErrorsAlwaysPresentEvenWhenDeferredPropPassedAsErrors(): void
    {
        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');

        // Pass 'errors' as a DeferProp — it must not be excluded; fallback to [] is guaranteed.
        $result = $this->response->build('Home', [
            'errors' => new DeferProp(static fn () => ['field' => 'error'], 'default'),
        ], '/home', null, $request);

        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('errors', $data['props']);
        self::assertSame([], $data['props']['errors']);
    }

    // -------------------------------------------------------------------------
    // Empty errors → JSON object {} (inertia-laravel: (object) [])
    // Raw JSON assertions: json_decode(..., true) maps both {} and [] to [].
    // -------------------------------------------------------------------------

    public function testErrorsWhenAbsentOnXhrVisitSerializedAsEmptyJsonObject(): void
    {
        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');

        $result = $this->response->build('Home', ['foo' => 'bar'], '/home', null, $request);

        self::assertStringContainsString('"errors":{}', (string) $result->getContent());
    }

    public function testErrorsWhenExplicitlyEmptyOnXhrVisitSerializedAsEmptyJsonObject(): void
    {
        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');

        $result = $this->response->build('Home', ['errors' => []], '/home', null, $request);

        self::assertStringContainsString('"errors":{}', (string) $result->getContent());
    }

    public function testErrorsWhenEmptyOnFirstVisitSerializedAsEmptyJsonObjectInScriptTag(): void
    {
        $result = $this->response->build('Home', ['errors' => []], '/home', null, Request::create('/home'));

        self::assertStringContainsString('"errors":{}', (string) $result->getContent());
    }

    public function testErrorsWhenNotEmptySerializedAsFieldMessageObject(): void
    {
        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');

        $result = $this->response->build('Home', ['errors' => ['email' => 'Invalid']], '/home', null, $request);

        self::assertStringContainsString('"errors":{"email":"Invalid"}', (string) $result->getContent());
    }

    // -------------------------------------------------------------------------
    // X-Inertia-Reset → key excluded from merge metadata so the client replaces
    // the value instead of merging it (inertia-laravel: collectMergeableMetadata)
    // -------------------------------------------------------------------------

    public function testResetHeaderOnMergePropExcludesKeyFromAllMergeMetadata(): void
    {
        $result = $this->response->build('Home', [
            'posts' => new MergeProp(static fn () => [['id' => 1]], matchOn: 'id'),
            'feed' => new MergeProp(static fn () => [['id' => 2]], prepend: true),
            'tree' => new MergeProp(static fn () => ['a' => 1], deep: true),
        ], '/home', null, $this->makeResetRequest('posts,feed,tree'));

        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);
        self::assertSame([['id' => 1]], $data['props']['posts']);
        self::assertArrayNotHasKey('mergeProps', $data);
        self::assertArrayNotHasKey('prependProps', $data);
        self::assertArrayNotHasKey('deepMergeProps', $data);
        self::assertArrayNotHasKey('matchPropsOn', $data);
    }

    public function testResetHeaderOnOneMergePropKeepsMergeMetadataOfOtherProps(): void
    {
        $request = $this->makeResetRequest('posts');
        $request->headers->set('X-Inertia-Partial-Data', 'posts,comments');

        $result = $this->response->build('Home', [
            'posts' => new MergeProp(static fn () => [1]),
            'comments' => new MergeProp(static fn () => [2]),
        ], '/home', null, $request);

        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(['comments'], $data['mergeProps']);
    }

    public function testResetHeaderOnDeferredMergePropExcludesKeyFromMergeMetadata(): void
    {
        $result = $this->response->build('Home', [
            'posts' => (new DeferProp(static fn () => [['id' => 1]]))->merge()->matchOn('id'),
        ], '/home', null, $this->makeResetRequest('posts'));

        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);
        self::assertSame([['id' => 1]], $data['props']['posts']);
        self::assertArrayNotHasKey('mergeProps', $data);
        self::assertArrayNotHasKey('matchPropsOn', $data);
    }

    public function testResetHeaderOnScrollPropExcludesKeyFromMergePropsButKeepsResetFlag(): void
    {
        $result = $this->response->build('Home', [
            'posts' => new ScrollProp(static fn () => [['id' => 1]], nextPage: 2),
        ], '/home', null, $this->makeResetRequest('posts'));

        $data = json_decode((string) $result->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('mergeProps', $data);
        self::assertArrayNotHasKey('prependProps', $data);
        self::assertTrue($data['scrollProps']['posts']['reset']);
    }

    /**
     * Mirrors the v3 client: reset keys are also appended to X-Inertia-Partial-Data.
     */
    private function makeResetRequest(string $keys): Request
    {
        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Inertia-Partial-Component', 'Home');
        $request->headers->set('X-Inertia-Partial-Data', $keys);
        $request->headers->set('X-Inertia-Reset', $keys);

        return $request;
    }
}
