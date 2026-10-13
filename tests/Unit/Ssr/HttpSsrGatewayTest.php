<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Unit\Ssr;

use Nytodev\InertiaBundle\Ssr\BundleDetectorInterface;
use Nytodev\InertiaBundle\Ssr\HttpSsrGateway;
use Nytodev\InertiaBundle\Ssr\SsrErrorType;
use Nytodev\InertiaBundle\Ssr\SsrException;
use Nytodev\InertiaBundle\Ssr\SsrRenderFailed;
use Nytodev\InertiaBundle\Ssr\SsrResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class HttpSsrGatewayTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $page = ['component' => 'Home', 'props' => [], 'url' => '/'];

    public function testDispatchSuccessfulResponseReturnsSsrResponse(): void
    {
        $body = json_encode([
            'head' => ['<title>Home</title>', '<meta name="desc" content="hello">'],
            'body' => '<div id="app"><h1>Home</h1></div>',
        ]);
        $client = new MockHttpClient(new MockResponse((string) $body));
        $gateway = new HttpSsrGateway($client, 'http://127.0.0.1:13714');

        $result = $gateway->dispatch($this->page);

        self::assertInstanceOf(SsrResponse::class, $result);
        self::assertSame("<title>Home</title>\n<meta name=\"desc\" content=\"hello\">", $result->head);
        self::assertSame('<div id="app"><h1>Home</h1></div>', $result->body);
    }

    public function testDispatchHeadArrayJoinedWithNewline(): void
    {
        $body = json_encode([
            'head' => ['<title>A</title>', '<title>B</title>', '<title>C</title>'],
            'body' => '<div id="app"></div>',
        ]);
        $client = new MockHttpClient(new MockResponse((string) $body));
        $gateway = new HttpSsrGateway($client, 'http://127.0.0.1:13714');

        $result = $gateway->dispatch($this->page);

        self::assertNotNull($result);
        self::assertSame("<title>A</title>\n<title>B</title>\n<title>C</title>", $result->head);
    }

    public function testDispatchEmptyHeadReturnsEmptyString(): void
    {
        $body = json_encode(['head' => [], 'body' => '<div id="app"></div>']);
        $client = new MockHttpClient(new MockResponse((string) $body));
        $gateway = new HttpSsrGateway($client, 'http://127.0.0.1:13714');

        $result = $gateway->dispatch($this->page);

        self::assertNotNull($result);
        self::assertSame('', $result->head);
    }

    public function testDispatchHttpErrorReturnsNull(): void
    {
        $client = new MockHttpClient(new MockResponse('Server Error', ['http_code' => 500]));
        $gateway = new HttpSsrGateway($client, 'http://127.0.0.1:13714');

        $result = $gateway->dispatch($this->page);

        self::assertNull($result);
    }

    public function testDispatchNetworkFailureReturnsNull(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));
        $gateway = new HttpSsrGateway($client, 'http://127.0.0.1:13714');

        $result = $gateway->dispatch($this->page);

        self::assertNull($result);
    }

    public function testDispatchInvalidJsonReturnsNull(): void
    {
        $client = new MockHttpClient(new MockResponse('not-json'));
        $gateway = new HttpSsrGateway($client, 'http://127.0.0.1:13714');

        $result = $gateway->dispatch($this->page);

        self::assertNull($result);
    }

    public function testDispatchEmptyObjectResponseDispatchesFailureAndReturnsNull(): void
    {
        $dispatcher = new EventDispatcher();
        $captured = null;
        $dispatcher->addListener(SsrRenderFailed::class, static function (SsrRenderFailed $e) use (&$captured): void {
            $captured = $e;
        });
        $gateway = new HttpSsrGateway(new MockHttpClient(new MockResponse('{}')), 'http://127.0.0.1:13714', dispatcher: $dispatcher);

        self::assertNull($gateway->dispatch($this->page));
        self::assertInstanceOf(SsrRenderFailed::class, $captured);
        self::assertStringContainsString('Invalid SSR response', $captured->error);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidResponseShapeProvider(): iterable
    {
        yield 'body is not a string' => [(string) json_encode(['head' => [], 'body' => ['<div>']])];
        yield 'head is not an array' => [(string) json_encode(['head' => '<title>Home</title>', 'body' => '<div></div>'])];
        yield 'head contains a non-string' => [(string) json_encode(['head' => [42], 'body' => '<div></div>'])];
    }

    #[DataProvider('invalidResponseShapeProvider')]
    public function testDispatchInvalidResponseShapeThrowsWhenThrowOnErrorEnabled(string $responseBody): void
    {
        $gateway = new HttpSsrGateway(new MockHttpClient(new MockResponse($responseBody)), 'http://127.0.0.1:13714', throwOnError: true);

        $this->expectException(SsrException::class);
        $this->expectExceptionMessage('Invalid SSR response');

        $gateway->dispatch($this->page);
    }

    public function testDispatchMissingHeadDefaultsToEmptyString(): void
    {
        $gateway = new HttpSsrGateway(new MockHttpClient(new MockResponse('{"body": "<div id=\"app\"></div>"}')), 'http://127.0.0.1:13714');

        $result = $gateway->dispatch($this->page);

        self::assertNotNull($result);
        self::assertSame('', $result->head);
        self::assertSame('<div id="app"></div>', $result->body);
    }

    public function testDispatchPassesTimeoutAsTimeoutAndMaxDuration(): void
    {
        $options = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $requestOptions) use (&$options): MockResponse {
            $options = $requestOptions;

            return new MockResponse('{"head": [], "body": "<div></div>"}');
        });
        $gateway = new HttpSsrGateway($client, 'http://127.0.0.1:13714', timeout: 2.5);

        $gateway->dispatch($this->page);

        self::assertSame(2.5, $options['timeout']);
        self::assertSame(2.5, $options['max_duration']);
    }

    public function testDispatchWithoutTimeoutKeepsClientDefaults(): void
    {
        $options = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $requestOptions) use (&$options): MockResponse {
            $options = $requestOptions;

            return new MockResponse('{"head": [], "body": "<div></div>"}');
        });
        $gateway = new HttpSsrGateway($client, 'http://127.0.0.1:13714');

        $gateway->dispatch($this->page);

        self::assertSame((float) \ini_get('default_socket_timeout'), $options['timeout']);
        self::assertSame(0.0, (float) $options['max_duration']);
    }

    public function testIsHealthyPassesTimeout(): void
    {
        $options = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $requestOptions) use (&$options): MockResponse {
            $options = $requestOptions;

            return new MockResponse('', ['http_code' => 200]);
        });
        $gateway = new HttpSsrGateway($client, 'http://127.0.0.1:13714', timeout: 1.0);

        self::assertTrue($gateway->isHealthy());
        self::assertSame(1.0, $options['timeout']);
        self::assertSame(1.0, $options['max_duration']);
    }

    public function testDispatchPostsToRenderEndpoint(): void
    {
        $capturedRequest = null;
        $callback = static function (string $method, string $url, array $options) use (&$capturedRequest): MockResponse {
            $capturedRequest = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? ''];

            return new MockResponse((string) json_encode([
                'head' => [],
                'body' => '<div id="app"></div>',
            ]));
        };

        $client = new MockHttpClient($callback);
        $gateway = new HttpSsrGateway($client, 'http://127.0.0.1:13714');
        $gateway->dispatch($this->page);

        self::assertNotNull($capturedRequest);
        self::assertSame('POST', $capturedRequest['method']);
        self::assertStringEndsWith('/render', $capturedRequest['url']);
        self::assertStringContainsString('"component"', $capturedRequest['body']);
    }

    public function testDispatchTrailingSlashInUrlNormalizesEndpoint(): void
    {
        $capturedUrl = null;
        $callback = static function (string $method, string $url) use (&$capturedUrl): MockResponse {
            $capturedUrl = $url;

            return new MockResponse((string) json_encode(['head' => [], 'body' => '<div id="app"></div>']));
        };

        $client = new MockHttpClient($callback);
        $gateway = new HttpSsrGateway($client, 'http://127.0.0.1:13714/');
        $gateway->dispatch($this->page);

        self::assertSame('http://127.0.0.1:13714/render', $capturedUrl);
    }

    // --- BundleDetector integration ---

    public function testDispatchReturnNullWhenBundleNotFound(): void
    {
        /** @var BundleDetectorInterface&MockObject $detector */
        $detector = $this->createMock(BundleDetectorInterface::class);
        $detector->method('detect')->willReturn(null);

        $client = new MockHttpClient(); // no requests expected
        $gateway = new HttpSsrGateway($client, 'http://127.0.0.1:13714', bundleDetector: $detector);

        self::assertNull($gateway->dispatch($this->page));
    }

    public function testDispatchProceedsWhenBundleFound(): void
    {
        /** @var BundleDetectorInterface&MockObject $detector */
        $detector = $this->createMock(BundleDetectorInterface::class);
        $detector->method('detect')->willReturn('/path/to/ssr.mjs');

        $body = json_encode(['head' => [], 'body' => '<div id="app"></div>']);
        $client = new MockHttpClient(new MockResponse((string) $body));
        $gateway = new HttpSsrGateway($client, 'http://127.0.0.1:13714', bundleDetector: $detector);

        self::assertInstanceOf(SsrResponse::class, $gateway->dispatch($this->page));
    }

    // --- SsrRenderFailed event ---

    public function testDispatchDispatchesEventOnHttpFailure(): void
    {
        /** @var BundleDetectorInterface&MockObject $detector */
        $detector = $this->createMock(BundleDetectorInterface::class);
        $detector->method('detect')->willReturn('/path/to/ssr.mjs');

        $errorBody = json_encode([
            'error' => 'window is not defined',
            'type' => 'browser-api',
            'hint' => 'Wrap in onMounted()',
            'browserApi' => 'window',
        ]);
        $client = new MockHttpClient(new MockResponse((string) $errorBody, ['http_code' => 500]));

        $dispatcher = new EventDispatcher();
        $captured = null;
        $dispatcher->addListener(SsrRenderFailed::class, static function (SsrRenderFailed $e) use (&$captured): void {
            $captured = $e;
        });

        $gateway = new HttpSsrGateway(
            $client,
            'http://127.0.0.1:13714',
            dispatcher: $dispatcher,
            bundleDetector: $detector,
        );

        self::assertNull($gateway->dispatch($this->page));
        self::assertInstanceOf(SsrRenderFailed::class, $captured);
        self::assertSame('window is not defined', $captured->error);
        self::assertSame(SsrErrorType::BrowserApi, $captured->type);
        self::assertSame('Wrap in onMounted()', $captured->hint);
        self::assertSame('window', $captured->browserApi);
    }

    public function testDispatchDispatchesEventOnConnectionFailure(): void
    {
        /** @var BundleDetectorInterface&MockObject $detector */
        $detector = $this->createMock(BundleDetectorInterface::class);
        $detector->method('detect')->willReturn('/path/to/ssr.mjs');

        $client = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));

        $dispatcher = new EventDispatcher();
        $captured = null;
        $dispatcher->addListener(SsrRenderFailed::class, static function (SsrRenderFailed $e) use (&$captured): void {
            $captured = $e;
        });

        $gateway = new HttpSsrGateway(
            $client,
            'http://127.0.0.1:13714',
            dispatcher: $dispatcher,
            bundleDetector: $detector,
        );

        self::assertNull($gateway->dispatch($this->page));
        self::assertInstanceOf(SsrRenderFailed::class, $captured);
        self::assertSame(SsrErrorType::Connection, $captured->type);
    }

    // --- throw_on_error ---

    public function testDispatchThrowsSsrExceptionWhenThrowOnErrorEnabled(): void
    {
        /** @var BundleDetectorInterface&MockObject $detector */
        $detector = $this->createMock(BundleDetectorInterface::class);
        $detector->method('detect')->willReturn('/path/to/ssr.mjs');

        $errorBody = json_encode([
            'error' => 'window is not defined',
            'type' => 'browser-api',
        ]);
        $client = new MockHttpClient(new MockResponse((string) $errorBody, ['http_code' => 500]));

        $gateway = new HttpSsrGateway(
            $client,
            'http://127.0.0.1:13714',
            throwOnError: true,
            bundleDetector: $detector,
        );

        $this->expectException(SsrException::class);
        $this->expectExceptionMessage('SSR render failed for component [Home]: window is not defined');

        $gateway->dispatch($this->page);
    }

    public function testDispatchDoesNotThrowWhenThrowOnErrorDisabled(): void
    {
        /** @var BundleDetectorInterface&MockObject $detector */
        $detector = $this->createMock(BundleDetectorInterface::class);
        $detector->method('detect')->willReturn('/path/to/ssr.mjs');

        $client = new MockHttpClient(new MockResponse('', ['http_code' => 500]));

        $gateway = new HttpSsrGateway(
            $client,
            'http://127.0.0.1:13714',
            throwOnError: false,
            bundleDetector: $detector,
        );

        self::assertNull($gateway->dispatch($this->page)); // no exception
    }

    // --- isHealthy ---

    public function testIsHealthyReturnsTrueOnHttp200(): void
    {
        $callback = static function (string $method, string $url): MockResponse {
            self::assertStringEndsWith('/health', $url);
            self::assertSame('GET', $method);

            return new MockResponse('', ['http_code' => 200]);
        };

        $gateway = new HttpSsrGateway(new MockHttpClient($callback), 'http://127.0.0.1:13714');

        self::assertTrue($gateway->isHealthy());
    }

    public function testIsHealthyReturnsFalseOnHttp500(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 500]));
        $gateway = new HttpSsrGateway($client, 'http://127.0.0.1:13714');

        self::assertFalse($gateway->isHealthy());
    }

    public function testIsHealthyReturnsFalseOnConnectionError(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));
        $gateway = new HttpSsrGateway($client, 'http://127.0.0.1:13714');

        self::assertFalse($gateway->isHealthy());
    }

    // --- except() path exclusion ---

    public function testExceptReturnNullWhenPathMatches(): void
    {
        /** @var BundleDetectorInterface&MockObject $detector */
        $detector = $this->createMock(BundleDetectorInterface::class);
        $detector->method('detect')->willReturn('/path/to/ssr.mjs');

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/admin/dashboard'));

        $gateway = new HttpSsrGateway(
            new MockHttpClient(),
            'http://127.0.0.1:13714',
            bundleDetector: $detector,
            requestStack: $requestStack,
        );
        $gateway->except(['admin/*']);

        self::assertNull($gateway->dispatch($this->page));
    }

    public function testExceptProceedsWhenPathDoesNotMatch(): void
    {
        /** @var BundleDetectorInterface&MockObject $detector */
        $detector = $this->createMock(BundleDetectorInterface::class);
        $detector->method('detect')->willReturn('/path/to/ssr.mjs');

        $body = json_encode(['head' => [], 'body' => '<div id="app"></div>']);
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/users'));

        $gateway = new HttpSsrGateway(
            new MockHttpClient(new MockResponse((string) $body)),
            'http://127.0.0.1:13714',
            bundleDetector: $detector,
            requestStack: $requestStack,
        );
        $gateway->except(['admin/*']);

        self::assertInstanceOf(SsrResponse::class, $gateway->dispatch($this->page));
    }

    public function testExceptAcceptsStringInsteadOfArray(): void
    {
        /** @var BundleDetectorInterface&MockObject $detector */
        $detector = $this->createMock(BundleDetectorInterface::class);
        $detector->method('detect')->willReturn('/path/to/ssr.mjs');

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/admin/settings'));

        $gateway = new HttpSsrGateway(
            new MockHttpClient(),
            'http://127.0.0.1:13714',
            bundleDetector: $detector,
            requestStack: $requestStack,
        );
        $gateway->except('admin/*');

        self::assertNull($gateway->dispatch($this->page));
    }

    public function testExceptCanBeCalledMultipleTimes(): void
    {
        /** @var BundleDetectorInterface&MockObject $detector */
        $detector = $this->createMock(BundleDetectorInterface::class);
        $detector->method('detect')->willReturn('/path/to/ssr.mjs');

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/nova/resources'));

        $gateway = new HttpSsrGateway(
            new MockHttpClient(),
            'http://127.0.0.1:13714',
            bundleDetector: $detector,
            requestStack: $requestStack,
        );
        $gateway->except('admin/*');
        $gateway->except(['nova/*', 'filament/*']);

        self::assertNull($gateway->dispatch($this->page));
    }
}
