<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Unit\Service;

use Nytodev\InertiaBundle\Props\AlwaysProp;
use Nytodev\InertiaBundle\Props\DeferProp;
use Nytodev\InertiaBundle\Props\MergeProp;
use Nytodev\InertiaBundle\Props\OnceProp;
use Nytodev\InertiaBundle\Props\OptionalProp;
use Nytodev\InertiaBundle\Props\ScrollProp;
use Nytodev\InertiaBundle\Response\InertiaResponse;
use Nytodev\InertiaBundle\Service\Inertia;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class InertiaTest extends TestCase
{
    /** @var RequestStack&MockObject */
    private RequestStack $requestStack;
    private InertiaResponse $inertiaResponse;

    protected function setUp(): void
    {
        $this->requestStack = $this->createMock(RequestStack::class);
        $twig = new Environment(new ArrayLoader([
            'base.html.twig' => '<body>{{ page|json_encode }}</body>',
        ]));
        $this->inertiaResponse = new InertiaResponse($twig, 'base.html.twig');
    }

    private function makeService(?string $version = null, bool $defaultEncryptHistory = false): Inertia
    {
        return new Inertia(
            $this->requestStack,
            $this->inertiaResponse,
            $version,
            $defaultEncryptHistory,
        );
    }

    public function testRenderWithBasicComponentReturnsResponse(): void
    {
        $request = new Request([], [], [], [], [], ['REQUEST_URI' => '/home']);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $service = $this->makeService();
        $response = $service->render('Home', ['foo' => 'bar']);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testRenderMergesSharedPropsWithComponentProps(): void
    {
        $request = new Request([], [], [], [], [], ['REQUEST_URI' => '/home']);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $service = $this->makeService();
        $service->share('shared', 'data');
        $response = $service->render('Home', ['local' => 'value']);

        $content = (string) $response->getContent();
        self::assertStringContainsString('shared', $content);
        self::assertStringContainsString('local', $content);
    }

    public function testRenderMergesSharedOncePropsWithComponentProps(): void
    {
        $request = new Request([], [], [], [], [], ['REQUEST_URI' => '/home']);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $service = $this->makeService();
        $service->shareOnce('once', 'flash');
        $response = $service->render('Home', ['local' => 'value']);

        $content = (string) $response->getContent();
        self::assertStringContainsString('once', $content);
        self::assertStringContainsString('local', $content);
    }

    public function testRenderWithInertiaXhrRequestReturnsJsonResponse(): void
    {
        $request = new Request([], [], [], [], [], ['REQUEST_URI' => '/home']);
        $request->headers->set('X-Inertia', 'true');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $service = $this->makeService();
        $response = $service->render('Home', []);

        self::assertStringContainsString('application/json', $response->headers->get('Content-Type') ?? '');
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('Home', $data['component']);
    }

    public function testShareWithKeyValueSharedPropAvailableOnNextRender(): void
    {
        $service = $this->makeService();
        $service->share('auth', ['user' => 'Tony']);

        self::assertSame(['auth' => ['user' => 'Tony']], $service->getSharedProps());
    }

    public function testShareOnceStoresOncePropInSharedProps(): void
    {
        $service = $this->makeService();
        $service->shareOnce('plans', 'value');

        $shared = $service->getSharedProps();
        self::assertArrayHasKey('plans', $shared);
        self::assertInstanceOf(OnceProp::class, $shared['plans']);
    }

    public function testShareOnceWithClosureStoresOncePropInSharedProps(): void
    {
        $service = $this->makeService();
        $service->shareOnce('plans', static fn () => ['basic', 'pro']);

        $shared = $service->getSharedProps();
        self::assertArrayHasKey('plans', $shared);
        self::assertInstanceOf(OnceProp::class, $shared['plans']);
        self::assertSame(['basic', 'pro'], $shared['plans']->resolve());
    }

    public function testVersionWhenConfiguredReturnsVersionString(): void
    {
        $service = $this->makeService('abc123');
        self::assertSame('abc123', $service->version());
    }

    public function testVersionWhenNotConfiguredReturnsNull(): void
    {
        $service = $this->makeService();
        self::assertNull($service->version());
    }

    public function testGetSharedPropsReturnsAllSharedProps(): void
    {
        $service = $this->makeService();
        $service->share('key1', 'value1');
        $service->share('key2', 'value2');

        self::assertSame(['key1' => 'value1', 'key2' => 'value2'], $service->getSharedProps());
    }

    public function testResetImplementsResetInterface(): void
    {
        $service = $this->makeService();
        self::assertInstanceOf(ResetInterface::class, $service);
    }

    public function testResetClearsSharedPropsIncludingOnceProps(): void
    {
        $service = $this->makeService();
        $service->share('auth', ['user' => 'Tony']);
        $service->shareOnce('flash', 'success');

        $service->reset();

        self::assertSame([], $service->getSharedProps());
    }

    public function testRenderThrowsLogicExceptionWhenNoCurrentRequest(): void
    {
        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(\LogicException::class);
        $service->render('Home', []);
    }

    public function testDeferWithClosureReturnsDeferPropWithDefaultGroup(): void
    {
        $service = $this->makeService();
        $callback = static fn () => 'deferred-value';

        $result = $service->defer($callback);

        self::assertInstanceOf(DeferProp::class, $result);
        self::assertSame('deferred-value', $result->resolve());
        self::assertSame('default', $result->getGroup());
    }

    public function testDeferWithGroupReturnsDeferPropWithCustomGroup(): void
    {
        $service = $this->makeService();
        $callback = static fn () => 'value';

        $result = $service->defer($callback, 'my-group');

        self::assertSame('my-group', $result->getGroup());
    }

    public function testOnceWithClosureReturnsOncePropInstance(): void
    {
        $service = $this->makeService();
        $callback = static fn () => 'once-value';

        $result = $service->once($callback);

        self::assertInstanceOf(OnceProp::class, $result);
        self::assertSame('once-value', $result->resolve());
    }

    public function testMergeWithClosureReturnsMergePropInstance(): void
    {
        $service = $this->makeService();
        $callback = static fn () => ['a', 'b'];

        $result = $service->merge($callback);

        self::assertInstanceOf(MergeProp::class, $result);
        self::assertSame(['a', 'b'], $result->resolve());
        self::assertFalse($result->isPrepend());
        self::assertFalse($result->isDeep());
    }

    public function testMergeWithPrependFlagReturnsMergePropWithPrependTrue(): void
    {
        $service = $this->makeService();
        $callback = static fn () => [];

        $result = $service->merge($callback, prepend: true);

        self::assertTrue($result->isPrepend());
        self::assertFalse($result->isDeep());
    }

    public function testMergeWithDeepFlagReturnsMergePropWithDeepTrue(): void
    {
        $service = $this->makeService();
        $callback = static fn () => [];

        $result = $service->merge($callback, deep: true);

        self::assertFalse($result->isPrepend());
        self::assertTrue($result->isDeep());
    }

    public function testClearHistoryWhenFlagSetPageObjectHasClearHistoryTrue(): void
    {
        $request = new Request([], [], [], [], [], ['REQUEST_URI' => '/home']);
        $request->headers->set('X-Inertia', 'true');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $service = $this->makeService();
        $service->clearHistory();
        $response = $service->render('Home', []);

        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertTrue($data['clearHistory']);
    }

    public function testEncryptHistoryWhenFlagSetPageObjectHasEncryptHistoryTrue(): void
    {
        $request = new Request([], [], [], [], [], ['REQUEST_URI' => '/home']);
        $request->headers->set('X-Inertia', 'true');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $service = $this->makeService();
        $service->encryptHistory();
        $response = $service->render('Home', []);

        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertTrue($data['encryptHistory']);
    }

    public function testClearHistoryIsOneShotFlagResetAfterRender(): void
    {
        $request = new Request([], [], [], [], [], ['REQUEST_URI' => '/home']);
        $request->headers->set('X-Inertia', 'true');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $service = $this->makeService();
        $service->clearHistory();
        $service->render('Home', []);

        // Second render — flag must be absent (reset to false → omitted in v3)
        $response = $service->render('Home', []);
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('clearHistory', $data);
    }

    public function testEncryptHistoryIsOneShotFlagResetAfterRender(): void
    {
        $request = new Request([], [], [], [], [], ['REQUEST_URI' => '/home']);
        $request->headers->set('X-Inertia', 'true');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $service = $this->makeService();
        $service->encryptHistory();
        $service->render('Home', []);

        // Second render — flag must be absent (reset to false → omitted in v3)
        $response = $service->render('Home', []);
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('encryptHistory', $data);
    }

    public function testResetClearsBothHistoryFlags(): void
    {
        $request = new Request([], [], [], [], [], ['REQUEST_URI' => '/home']);
        $request->headers->set('X-Inertia', 'true');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $service = $this->makeService();
        $service->clearHistory();
        $service->encryptHistory();
        $service->reset();

        $response = $service->render('Home', []);
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('clearHistory', $data);
        self::assertArrayNotHasKey('encryptHistory', $data);
    }

    public function testDefaultEncryptHistoryTrueProducesEncryptedResponseWithoutExplicitCall(): void
    {
        $request = new Request([], [], [], [], [], ['REQUEST_URI' => '/home']);
        $request->headers->set('X-Inertia', 'true');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $service = $this->makeService(defaultEncryptHistory: true);

        $response = $service->render('Home', []);
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertTrue($data['encryptHistory']);
    }

    public function testResetRestoresDefaultEncryptHistoryFromConfig(): void
    {
        $request = new Request([], [], [], [], [], ['REQUEST_URI' => '/home']);
        $request->headers->set('X-Inertia', 'true');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $service = $this->makeService(defaultEncryptHistory: true);
        $service->reset();

        $response = $service->render('Home', []);
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertTrue($data['encryptHistory']);
    }

    // -------------------------------------------------------------------------
    // errors() unit tests
    // -------------------------------------------------------------------------

    public function testErrorsWithNoSessionStoresInMemory(): void
    {
        $request = new Request([], [], [], [], [], ['REQUEST_URI' => '/home']);
        $request->headers->set('X-Inertia', 'true');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $service = $this->makeService();
        $service->errors(['email' => 'Invalid email']);
        $response = $service->render('Home', []);

        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(['email' => 'Invalid email'], $data['props']['errors']);
    }

    public function testErrorsWithNamedBagStoredUnderBagName(): void
    {
        $request = new Request([], [], [], [], [], ['REQUEST_URI' => '/home']);
        $request->headers->set('X-Inertia', 'true');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $service = $this->makeService();
        $service->errors(['name' => 'Required'], 'login');
        $response = $service->render('Home', []);

        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(['login' => ['name' => 'Required']], $data['props']['errors']);
    }

    public function testFlashBagArrayErrorsAreRenderedAndStringMessagesKeptForTwig(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->getFlashBag()->add('errors', ['email' => 'Invalid']);
        $session->getFlashBag()->add('errors', 'Something went wrong.');
        $request = new Request([], [], [], [], [], ['REQUEST_URI' => '/home']);
        $request->headers->set('X-Inertia', 'true');
        $request->setSession($session);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $response = $this->makeService()->render('Home', []);

        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(['email' => 'Invalid'], $data['props']['errors']);
        self::assertSame(['Something went wrong.'], $session->getFlashBag()->peek('errors'));
    }

    public function testStatelessRouteNeverTouchesTheSession(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::never())->method(self::anything());
        $request = new Request([], [], [], [], [], ['REQUEST_URI' => '/home']);
        $request->headers->set('X-Inertia', 'true');
        $request->attributes->set('_stateless', true);
        $request->setSession($session);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $service = $this->makeService();
        $service->flash('status', 'saved');
        $service->errors(['email' => 'Invalid']);
        $service->clearHistory();
        $service->preserveFragment();
        $response = $service->render('Home', []);
        $service->reset();

        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(['status' => 'saved'], $data['flash']);
        self::assertSame(['email' => 'Invalid'], $data['props']['errors']);
        self::assertTrue($data['clearHistory']);
        self::assertTrue($data['preserveFragment']);
    }

    public function testResetClearsErrorsData(): void
    {
        $request = new Request([], [], [], [], [], ['REQUEST_URI' => '/home']);
        $request->headers->set('X-Inertia', 'true');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $service = $this->makeService();
        $service->errors(['email' => 'Invalid email']);
        $service->reset();
        $response = $service->render('Home', []);

        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertSame([], $data['props']['errors']);
    }

    public function testAlwaysWithClosureReturnsAlwaysPropInstance(): void
    {
        $service = $this->makeService();
        $callback = static fn () => 'always-value';

        $result = $service->always($callback);

        self::assertInstanceOf(AlwaysProp::class, $result);
        self::assertSame('always-value', $result->resolve());
    }

    public function testScrollWithClosureReturnsScrollPropInstance(): void
    {
        $service = $this->makeService();
        $callback = static fn () => [['id' => 1]];

        $result = $service->scroll($callback, 'page', 2, 1, 1);

        self::assertInstanceOf(ScrollProp::class, $result);
        self::assertSame(2, $result->getNextPage());
    }

    public function testFlashWithNoSessionStoresInMemoryAndAppearsAsTopLevelKey(): void
    {
        $request = new Request([], [], [], [], [], ['REQUEST_URI' => '/home']);
        $request->headers->set('X-Inertia', 'true');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $service = $this->makeService();
        $service->flash('status', 'saved');
        $response = $service->render('Home', []);

        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('saved', $data['flash']['status'] ?? null);
        self::assertArrayNotHasKey('flash', $data['props']);
    }

    public function testShareOnceWithExistingOncePropPassesThroughUnchanged(): void
    {
        $service = $this->makeService();
        $onceProp = new OnceProp(static fn () => 'value');

        $service->shareOnce('key', $onceProp);

        $shared = $service->getSharedProps();
        self::assertSame($onceProp, $shared['key']);
    }

    public function testLocationThrowsLogicExceptionWhenNoCurrentRequest(): void
    {
        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        $service = $this->makeService();

        $this->expectException(\LogicException::class);
        $service->location('/some-url');
    }

    public function testOptionalWithClosureReturnsOptionalPropInstance(): void
    {
        $service = $this->makeService();
        $result = $service->optional(static fn () => 'optional-value');
        self::assertInstanceOf(OptionalProp::class, $result);
        self::assertSame('optional-value', $result->resolve());
    }

    public function testDeepMergeWithClosureReturnsMergePropWithDeepTrue(): void
    {
        $service = $this->makeService();
        $result = $service->deepMerge(static fn () => ['a' => [1]]);
        self::assertInstanceOf(MergeProp::class, $result);
        self::assertTrue($result->isDeep());
        self::assertFalse($result->isPrepend());
        self::assertSame(['a' => [1]], $result->resolve());
    }

    public function testDeepMergeWithMatchOnPassedThrough(): void
    {
        $service = $this->makeService();
        $result = $service->deepMerge(static fn () => [], matchOn: 'id');
        self::assertSame(['id'], $result->getMatchOn());
    }
}
