<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Service;

use Nytodev\InertiaBundle\Props\AlwaysProp;
use Nytodev\InertiaBundle\Props\DeferProp;
use Nytodev\InertiaBundle\Props\MergeProp;
use Nytodev\InertiaBundle\Props\OnceProp;
use Nytodev\InertiaBundle\Props\OptionalProp;
use Nytodev\InertiaBundle\Props\ScrollProp;
use Nytodev\InertiaBundle\Response\InertiaResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Main Inertia service. Manages shared props and delegates response building
 * to InertiaResponse.
 */
final class Inertia implements ResetInterface
{
    /** @var array<string, mixed> */
    private array $sharedProps = [];

    /**
     * In-memory fallback for flash data when no session is available.
     *
     * @var array<string, mixed>
     */
    private array $flashData = [];

    /**
     * In-memory fallback for errors data when no session is available.
     * Keyed by bag name (e.g. 'default', 'login').
     *
     * @var array<string, array<string, mixed>>
     */
    private array $errorsData = [];

    private bool $clearHistory = false;

    private bool $encryptHistory = false;

    private bool $preserveFragment = false;

    /** @var callable|null */
    private $exceptionCallback;

    /**
     * @param string[] $pagePaths
     * @param string[] $pageExtensions
     */
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly InertiaResponse $inertiaResponse,
        private readonly ?string $version,
        private readonly bool $defaultEncryptHistory = false,
        private readonly bool $exposeSharedPropKeys = true,
        private readonly bool $ensurePagesExist = false,
        private readonly array $pagePaths = [],
        private readonly array $pageExtensions = ['vue', 'jsx', 'tsx', 'js', 'ts', 'svelte'],
    ) {
        $this->encryptHistory = $defaultEncryptHistory;
    }

    /**
     * Render an Inertia component. Merges shared props with the given props
     * and returns either an HTML or JSON response based on the request headers.
     *
     * @param \BackedEnum|\UnitEnum|string $component            Component name or enum resolving to a string
     * @param array<string, mixed>         $props
     * @param array<string, mixed>|null    $serializationContext When non-null, the fully built Inertia page
     *                                                           payload (component, url, version, props and all
     *                                                           metadata) is normalized via Symfony Serializer after
     *                                                           all prop types are resolved. Requires
     *                                                           symfony/serializer installed and the Serializer
     *                                                           service enabled (framework.serializer in your config).
     */
    public function render(\BackedEnum|\UnitEnum|string $component, array $props = [], ?array $serializationContext = null): Response
    {
        $component = match (true) {
            $component instanceof \BackedEnum => $component->value,
            $component instanceof \UnitEnum => $component->name,
            default => $component,
        };

        if (!\is_string($component)) {
            throw new \InvalidArgumentException('Component name must resolve to a string (int-backed enums are not supported).');
        }

        if ($this->ensurePagesExist && [] !== $this->pagePaths) {
            $this->findComponentOrFail($component);
        }

        $request = $this->requestStack->getCurrentRequest()
            ?? throw new \LogicException('No current request.');

        $mergedProps = array_merge($this->sharedProps, $props);

        $clearHistory = $this->consumeClearHistory($request);
        $encryptHistory = $this->encryptHistory;
        $this->encryptHistory = $this->defaultEncryptHistory;
        $preserveFragment = $this->consumePreserveFragment($request);

        $flash = $this->consumeFlash($request);

        // Inject errors only when the caller has not already provided an 'errors' key.
        if (!\array_key_exists('errors', $mergedProps)) {
            $mergedProps['errors'] = $this->consumeErrors($request);
        } else {
            // Discard pending errors so they do not leak to the next request.
            $this->consumeErrors($request);
        }

        $sharedPropKeys = $this->exposeSharedPropKeys ? array_keys($this->sharedProps) : [];

        return $this->inertiaResponse->build(
            $component,
            $mergedProps,
            $request->getBaseUrl().$request->getPathInfo().(($queryString = $request->getQueryString()) !== null && '' !== $queryString ? '?'.$queryString : ''),
            $this->version,
            $request,
            $clearHistory,
            $encryptHistory,
            $flash,
            $sharedPropKeys,
            $preserveFragment,
            $serializationContext,
        );
    }

    /**
     * Share a prop on every subsequent Inertia render for this request lifecycle.
     */
    public function share(string $key, mixed $value): void
    {
        $this->sharedProps[$key] = $value;
    }

    /**
     * Share a prop that is sent once and cached by the client.
     * The value is wrapped in an OnceProp and stored in $sharedProps.
     * The client controls re-sending via X-Inertia-Except-Once-Props.
     */
    public function shareOnce(string $key, mixed $value): void
    {
        $this->sharedProps[$key] = $value instanceof OnceProp
            ? $value
            : new OnceProp($value instanceof \Closure ? $value : static fn () => $value);
    }

    /**
     * Return the configured asset version string (null if versioning is disabled).
     */
    public function version(): ?string
    {
        return $this->version;
    }

    /**
     * Register a callback to handle exceptions for Inertia requests.
     * The callback receives an ExceptionResponse and may call render() on it
     * to replace the default error page with an Inertia component.
     *
     * Must be called at boot time (e.g. kernel listener, bundle config) — not per request.
     * In FrankenPHP worker mode, this callback is shared across all requests for the
     * worker's lifetime; a per-request call would leak into subsequent requests.
     */
    public function handleExceptionsUsing(callable $callback): void
    {
        $this->exceptionCallback = $callback;
    }

    public function getExceptionCallback(): ?callable
    {
        return $this->exceptionCallback;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSharedProps(): array
    {
        return $this->sharedProps;
    }

    /**
     * Flash a key/value pair into the page object for the next Inertia render.
     *
     * When a session is available, the value is stored in the session under
     * '_inertia_flash' so it survives redirects (e.g. POST → 303 → GET).
     * When no session is available (stateless routes), the value is kept in
     * memory and is available only within the same request lifecycle.
     *
     * NOTE: Flash values must be serializable (same constraint as any session data).
     */
    public function flash(string $key, mixed $value): void
    {
        $session = $this->session($this->requestStack->getCurrentRequest());

        if (null !== $session) {
            /** @var array<string, mixed> $existing */
            $existing = $session->get('_inertia_flash', []);
            $existing[$key] = $value;
            $session->set('_inertia_flash', $existing);

            return;
        }

        $this->flashData[$key] = $value;
    }

    /**
     * Reset service state between requests (FrankenPHP / ReactPHP persistent workers).
     * Called automatically by the container via the kernel.reset tag.
     */
    public function reset(): void
    {
        $session = $this->session($this->requestStack->getCurrentRequest());
        if (null !== $session) {
            $session->remove('inertia.clear_history');
            $session->remove('inertia.preserve_fragment');
        }

        $this->sharedProps = [];
        $this->flashData = [];
        $this->errorsData = [];
        $this->clearHistory = false;
        $this->encryptHistory = $this->defaultEncryptHistory;
        $this->preserveFragment = false;
        // Note: exceptionCallback is boot-time configuration — not reset between requests.
    }

    /**
     * Read and clear all pending flash data (session + in-memory fallback).
     * Called exactly once per render() invocation.
     *
     * @return array<string, mixed>
     */
    private function consumeFlash(Request $request): array
    {
        $flash = $this->flashData;
        $this->flashData = [];

        $session = $this->session($request);
        if (null !== $session) {
            /** @var array<string, mixed> $sessionFlash */
            $sessionFlash = $session->get('_inertia_flash', []);
            $session->remove('_inertia_flash');
            $flash = array_merge($sessionFlash, $flash);
        }

        return $flash;
    }

    /**
     * Store validation errors to be auto-injected into the next Inertia render.
     *
     * When a session is available, the errors are persisted under '_inertia_errors'
     * so they survive redirects (PRG pattern: PUT → 303 → GET).
     * When no session is available (stateless routes), errors are kept in memory
     * and are available only within the same request lifecycle.
     *
     * Multiple calls merge errors within each bag rather than overwriting.
     *
     * @param array<string, mixed> $errors
     */
    public function errors(array $errors, string $bag = 'default'): void
    {
        $session = $this->session($this->requestStack->getCurrentRequest());

        if (null !== $session) {
            /** @var array<string, array<string, mixed>> $existing */
            $existing = $session->get('_inertia_errors', []);
            $existing[$bag] = array_merge($existing[$bag] ?? [], $errors);
            $session->set('_inertia_errors', $existing);

            return;
        }

        $this->errorsData[$bag] = array_merge($this->errorsData[$bag] ?? [], $errors);
    }

    /**
     * Read and clear all pending errors (session, FlashBag 'errors' and in-memory fallback).
     * Same shape as inertia-laravel resolveValidationErrors(), which the client relies on:
     * - 'default' bag present → only that bag, wrapped under X-Inertia-Error-Bag when sent
     * - no 'default' bag → every bag keyed by name.
     *
     * Called exactly once per render() invocation.
     *
     * @return array<string, mixed>
     */
    private function consumeErrors(Request $request): array
    {
        /** @var array<string, array<string, mixed>> $bags */
        $bags = $this->errorsData;
        $this->errorsData = [];

        $session = $this->session($request);
        if (null !== $session) {
            /** @var array<string, array<string, mixed>> $sessionErrors */
            $sessionErrors = $session->get('_inertia_errors', []);
            $session->remove('_inertia_errors');
            foreach ($sessionErrors as $bagName => $bagErrors) {
                $bags[$bagName] = array_merge($bags[$bagName] ?? [], $bagErrors);
            }

            $flashBagErrors = $session instanceof FlashBagAwareSessionInterface ? $this->pullFlashBagErrors($session->getFlashBag()) : [];
            if ([] !== $flashBagErrors) {
                $bags['default'] = array_merge($bags['default'] ?? [], $flashBagErrors);
            }
        }

        if (!\array_key_exists('default', $bags)) {
            return $bags;
        }

        $errorBagHeader = $request->headers->get('X-Inertia-Error-Bag');
        if (null !== $errorBagHeader && '' !== $errorBagHeader) {
            return [$errorBagHeader => $bags['default']];
        }

        return $bags['default'];
    }

    /**
     * Array entries flashed under 'errors' (addFlash('errors', ['email' => '…'])) are field errors;
     * string entries are plain messages and stay in the FlashBag for Twig templates.
     *
     * @return array<string, mixed>
     */
    private function pullFlashBagErrors(FlashBagInterface $flashBag): array
    {
        $errors = [];
        $messages = [];

        foreach ($flashBag->get('errors') as $entry) {
            if (\is_array($entry)) {
                $errors = array_merge($errors, $entry);
            } else {
                $messages[] = $entry;
            }
        }

        if ([] !== $messages) {
            $flashBag->set('errors', $messages);
        }

        return $errors;
    }

    /**
     * Signal that the browser history entry for this response should be cleared.
     * The flag is stored in the session so it survives a redirect (same as inertia-laravel).
     * Falls back to an in-memory property when no session is available (e.g. unit tests).
     */
    public function clearHistory(): void
    {
        $session = $this->session($this->requestStack->getCurrentRequest());
        if (null !== $session) {
            $session->set('inertia.clear_history', true);

            return;
        }

        $this->clearHistory = true;
    }

    /**
     * Read and clear the clearHistory flag from the session (or in-memory fallback).
     * Called exactly once per render() invocation.
     */
    private function consumeClearHistory(Request $request): bool
    {
        $session = $this->session($request);
        if (null !== $session) {
            $value = (bool) $session->get('inertia.clear_history', false);
            $session->remove('inertia.clear_history');

            return $value;
        }

        $value = $this->clearHistory;
        $this->clearHistory = false;

        return $value;
    }

    /**
     * Signal that the browser history entry for this response should be encrypted.
     * The flag is consumed on the next render() call and then reset to false.
     */
    public function encryptHistory(): void
    {
        $this->encryptHistory = true;
    }

    /**
     * Signal that the URL fragment should be preserved when the client navigates
     * after a redirect. The flag is stored in the session so it survives a redirect
     * (same pattern as clearHistory). Falls back to an in-memory property when no
     * session is available.
     */
    public function preserveFragment(): void
    {
        $session = $this->session($this->requestStack->getCurrentRequest());
        if (null !== $session) {
            $session->set('inertia.preserve_fragment', true);

            return;
        }

        $this->preserveFragment = true;
    }

    /**
     * Read and clear the preserveFragment flag from the session (or in-memory fallback).
     * Called exactly once per render() invocation.
     */
    private function consumePreserveFragment(Request $request): bool
    {
        $session = $this->session($request);
        if (null !== $session) {
            $value = (bool) $session->get('inertia.preserve_fragment', false);
            $session->remove('inertia.preserve_fragment');

            return $value;
        }

        $value = $this->preserveFragment;
        $this->preserveFragment = false;

        return $value;
    }

    public function always(\Closure $callback): AlwaysProp
    {
        return new AlwaysProp($callback);
    }

    public function optional(\Closure $callback): OptionalProp
    {
        return new OptionalProp($callback);
    }

    public function defer(\Closure $callback, string $group = 'default'): DeferProp
    {
        return new DeferProp($callback, $group);
    }

    public function once(\Closure $callback): OnceProp
    {
        return new OnceProp($callback);
    }

    /**
     * @param string|string[] $matchOn         one or more field names for client-side deduplication
     * @param string|string[] $appendsAtPaths  sub-paths to merge instead of the root prop (e.g. 'data' → 'posts.data')
     * @param string|string[] $prependsAtPaths sub-paths to prepend instead of the root prop
     */
    public function merge(\Closure $callback, bool $prepend = false, bool $deep = false, string|array $matchOn = [], string|array $appendsAtPaths = [], string|array $prependsAtPaths = []): MergeProp
    {
        return new MergeProp($callback, $prepend, $deep, $matchOn, $appendsAtPaths, $prependsAtPaths);
    }

    /**
     * Shorthand for merge() with deep=true.
     *
     * @param string|string[] $matchOn         one or more field names for client-side deduplication
     * @param string|string[] $appendsAtPaths  sub-paths to deep-merge instead of the root prop
     * @param string|string[] $prependsAtPaths sub-paths to prepend instead of the root prop
     */
    public function deepMerge(\Closure $callback, string|array $matchOn = [], string|array $appendsAtPaths = [], string|array $prependsAtPaths = []): MergeProp
    {
        return new MergeProp($callback, false, true, $matchOn, $appendsAtPaths, $prependsAtPaths);
    }

    /**
     * Force a full browser navigation to the given URL, bypassing the SPA.
     *
     * For Inertia XHR requests: returns 409 Conflict + X-Inertia-Location header.
     * For first-visit (non-XHR) requests: returns a standard 302 redirect.
     */
    public function location(string $url): Response
    {
        $request = $this->requestStack->getCurrentRequest()
            ?? throw new \LogicException('No current request.');

        if ($request->headers->has('X-Inertia')) {
            return new Response('', 409, ['X-Inertia-Location' => $url]);
        }

        return new RedirectResponse($url, 302);
    }

    /**
     * Session holding the Inertia state, or null when it must not be touched: none configured,
     * or a `_stateless` route, where any read throws UnexpectedSessionUsageException in debug.
     */
    private function session(?Request $request): ?SessionInterface
    {
        if (null === $request || !$request->hasSession() || $request->attributes->getBoolean('_stateless')) {
            return null;
        }

        return $request->getSession();
    }

    /**
     * Validate that a component file exists on disk relative to configured paths × extensions.
     * Throws InvalidArgumentException if no matching file is found.
     */
    private function findComponentOrFail(string $component): void
    {
        $normalized = str_replace('\\', '/', $component);

        foreach ($this->pagePaths as $path) {
            foreach ($this->pageExtensions as $ext) {
                if (file_exists($path.'/'.$normalized.'.'.$ext)) {
                    return;
                }
            }
        }

        throw new \InvalidArgumentException("Inertia page component [{$component}] not found.");
    }

    public function scroll(
        \Closure $callback,
        string $pageName = 'page',
        int|string|null $nextPage = null,
        int|string|null $previousPage = null,
        int|string|null $currentPage = null,
        bool $prepend = false,
    ): ScrollProp {
        return new ScrollProp($callback, $pageName, $nextPage, $previousPage, $currentPage, $prepend);
    }
}
