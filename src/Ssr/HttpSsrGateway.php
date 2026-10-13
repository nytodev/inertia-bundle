<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Ssr;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Dispatches the Inertia page object to the Node.js SSR server via HTTP.
 * POST {ssrUrl}/render → { head: string[], body: string }
 * Returns null on any error so the caller falls back to client-side rendering.
 */
final class HttpSsrGateway implements SsrGatewayInterface
{
    /** @var list<string> */
    private array $exceptPaths = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $ssrUrl,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?BundleDetectorInterface $bundleDetector = null,
        private readonly bool $throwOnError = false,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?float $timeout = null,
    ) {
    }

    public function dispatch(array $page): ?SsrResponse
    {
        if ($this->isExcluded()) {
            return null;
        }

        if (null !== $this->bundleDetector && null === $this->bundleDetector->detect()) {
            return null;
        }

        $url = rtrim($this->ssrUrl, '/').'/render';

        try {
            $response = $this->httpClient->request('POST', $url, ['json' => $page] + $this->timeoutOptions());

            $statusCode = $response->getStatusCode();

            if (400 <= $statusCode) {
                $errorData = null;
                try {
                    /** @var array<string, mixed> $errorData */
                    $errorData = $response->toArray(false);
                } catch (\Throwable) {
                }

                $this->handleFailure($page, $errorData);

                return null;
            }

            $data = $response->toArray();
        } catch (ExceptionInterface $e) {
            $this->handleFailure($page, [
                'error' => $e->getMessage(),
                'type' => 'connection',
            ]);

            return null;
        }

        $head = $data['head'] ?? [];
        $body = $data['body'] ?? null;

        if (!\is_string($body) || !\is_array($head) || [] !== array_filter($head, static fn (mixed $tag): bool => !\is_string($tag))) {
            $this->handleFailure($page, ['error' => 'Invalid SSR response: expected {"head": string[], "body": string}.']);

            return null;
        }

        return new SsrResponse(implode("\n", $head), $body);
    }

    /**
     * @param list<string>|string $paths
     */
    public function except(array|string $paths): void
    {
        $this->exceptPaths = array_merge(
            $this->exceptPaths,
            \is_array($paths) ? $paths : [$paths],
        );
    }

    public function isHealthy(): bool
    {
        $url = rtrim($this->ssrUrl, '/').'/health';

        try {
            $response = $this->httpClient->request('GET', $url, $this->timeoutOptions());

            return 200 === $response->getStatusCode();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Same value for both: `timeout` alone is an idle timeout, `max_duration` bounds the whole request.
     *
     * @return array{timeout?: float, max_duration?: float}
     */
    private function timeoutOptions(): array
    {
        return null === $this->timeout ? [] : ['timeout' => $this->timeout, 'max_duration' => $this->timeout];
    }

    private function isExcluded(): bool
    {
        if ([] === $this->exceptPaths || null === $this->requestStack) {
            return false;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return false;
        }

        $path = ltrim($request->getPathInfo(), '/');

        foreach ($this->exceptPaths as $pattern) {
            // Support both relative (admin/*) and absolute (http://host/admin/*) patterns
            $normalizedPattern = ltrim((string) parse_url($pattern, \PHP_URL_PATH), '/');
            if (fnmatch($normalizedPattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>      $page
     * @param array<string, mixed>|null $error
     *
     * @throws SsrException
     */
    private function handleFailure(array $page, ?array $error): void
    {
        $event = new SsrRenderFailed(
            page: $page,
            error: $error['error'] ?? 'Unknown SSR error',
            type: SsrErrorType::fromString(isset($error['type']) && \is_string($error['type']) ? $error['type'] : null),
            hint: isset($error['hint']) && \is_string($error['hint']) ? $error['hint'] : null,
            browserApi: isset($error['browserApi']) && \is_string($error['browserApi']) ? $error['browserApi'] : null,
            stack: isset($error['stack']) && \is_string($error['stack']) ? $error['stack'] : null,
            sourceLocation: isset($error['sourceLocation']) && \is_string($error['sourceLocation']) ? $error['sourceLocation'] : null,
        );

        $this->dispatcher?->dispatch($event, SsrRenderFailed::class);

        if ($this->throwOnError) {
            throw SsrException::fromEvent($event);
        }
    }
}
