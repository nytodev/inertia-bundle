<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Response;

use Nytodev\InertiaBundle\Props\AlwaysProp;
use Nytodev\InertiaBundle\Props\DeferProp;
use Nytodev\InertiaBundle\Props\MergeProp;
use Nytodev\InertiaBundle\Props\OnceProp;
use Nytodev\InertiaBundle\Props\OptionalProp;
use Nytodev\InertiaBundle\Props\ScrollProp;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Twig\Environment;

/**
 * Builds the Inertia page object and returns the appropriate HTTP response:
 * - HTML (with <script type="application/json"> tag) on first visit
 * - JSON (page object) on Inertia XHR visits
 */
final class InertiaResponse
{
    public function __construct(
        private readonly Environment $twig,
        private readonly string $rootView,
        private readonly ?object $normalizer = null,
    ) {
    }

    /**
     * Build and return an HTML or JSON response based on the request type.
     *
     * @param array<string, mixed>      $props
     * @param array<string, mixed>      $flash                flash data emitted as top-level page object key (omitted when empty, matching inertia-laravel)
     * @param list<string>              $sharedPropKeys       keys from Inertia::share(); emitted as top-level sharedProps when non-empty
     * @param array<string, mixed>|null $serializationContext when non-null, the fully resolved page array is normalized using the configured NormalizerInterface
     */
    public function build(
        string $component,
        array $props,
        string $url,
        ?string $version,
        Request $request,
        bool $clearHistory = false,
        bool $encryptHistory = false,
        array $flash = [],
        array $sharedPropKeys = [],
        bool $preserveFragment = false,
        ?array $serializationContext = null,
    ): Response {
        $only = $this->parseCsv($request->headers->get('X-Inertia-Partial-Data') ?? '');
        $except = $this->parseCsv($request->headers->get('X-Inertia-Partial-Except') ?? '');
        $exceptOnce = $this->parseCsv($request->headers->get('X-Inertia-Except-Once-Props') ?? '');
        $reset = $this->parseCsv($request->headers->get('X-Inertia-Reset') ?? '');
        $partialComponent = $request->headers->get('X-Inertia-Partial-Component') ?? '';

        $isPartial = ([] !== $only || [] !== $except) && $partialComponent === $component;

        // Collect deferred prop groups before resolving.
        // Both DeferProp and deferred ScrollProp participate in deferredProps.
        $deferredGroups = [];
        foreach ($props as $key => $prop) {
            if ($prop instanceof DeferProp) {
                $deferredGroups[$prop->getGroup()][] = $key;
            } elseif ($prop instanceof ScrollProp && $prop->shouldDefer()) {
                $deferredGroups[$prop->getGroup()][] = $key;
            }
        }

        $resolved = $this->resolveProps($props, $only, $except, $exceptOnce, $isPartial);

        // Guarantee: errors must always be present, even if a DeferProp/OptionalProp was passed as 'errors'.
        // Empty errors must serialize as {} — an empty PHP array would encode as [] (inertia-laravel: (object) []).
        if (!\array_key_exists('errors', $resolved) || [] === $resolved['errors']) {
            $resolved['errors'] = new \stdClass();
        }

        // Collect merge arrays AFTER resolving+filtering so excluded keys are absent.
        $scrollMergeIntent = $request->headers->get('X-Inertia-Infinite-Scroll-Merge-Intent');
        [$mergeProps, $prependProps, $deepMergeProps, $matchPropsOn] = $this->collectMergeArrays($props, $resolved, $reset, $scrollMergeIntent, $isPartial);
        $scrollProps = $this->collectScrollProps($props, $resolved, $reset);

        // Collect onceProps metadata for ALL OnceProp instances, even when skipped via
        // X-Inertia-Except-Once-Props. The client uses this metadata to re-inject the
        // cached value when the prop is absent from the response (full SPA navigation).
        // DeferProp::once() also emits onceProps metadata so the client caches the
        // resolved value after the first deferred XHR and skips subsequent re-fetches.
        $oncePropsMeta = [];
        foreach ($props as $key => $prop) {
            if ($prop instanceof OnceProp) {
                $expiresAt = $prop->getExpiresAt();
                $alias = $prop->getAlias();
                $meta = [
                    'prop' => $key,
                    // Milliseconds: the client compares it with Date.now().
                    'expiresAt' => null !== $expiresAt ? $expiresAt->getTimestamp() * 1000 : null,
                ];
                if ($prop->isFresh()) {
                    $meta['fresh'] = true;
                }
                $oncePropsMeta[$alias ?? $key] = $meta;
                continue;
            }
            if ($prop instanceof DeferProp && $prop->isOnce()) {
                $oncePropsMeta[$key] = [
                    'prop' => $key,
                    'expiresAt' => null,
                ];
                continue;
            }
            if ($prop instanceof OptionalProp && $prop->isOnce()) {
                $oncePropsMeta[$key] = [
                    'prop' => $key,
                    'expiresAt' => null,
                ];
            }
        }

        $page = $this->buildPageObject(
            $component,
            $resolved,
            $url,
            $version,
            $clearHistory,
            $encryptHistory,
            $isPartial ? [] : $deferredGroups,
            $mergeProps,
            $prependProps,
            $deepMergeProps,
            $oncePropsMeta,
            $matchPropsOn,
            $scrollProps,
            $sharedPropKeys,
            $flash,
            $preserveFragment,
        );

        // Optional Symfony Serializer normalization — opt-in via $serializationContext.
        // Runs on the full page object AFTER all props are resolved and the page is built,
        // so lazy/defer/once semantics are fully preserved.
        if (null !== $serializationContext) {
            $page = $this->normalizePage($page, $serializationContext);
        }

        if ($request->headers->has('X-Inertia')) {
            return new JsonResponse($page, 200, [
                'X-Inertia' => 'true',
                'Vary' => 'X-Inertia',
            ]);
        }

        $html = $this->twig->render($this->rootView, ['page' => $page]);

        return new Response($html, 200, ['Vary' => 'X-Inertia']);
    }

    /**
     * Filter and resolve prop values according to partial reload headers.
     * - OptionalProp: skipped on full render, resolved on partial ONLY if explicitly in $only
     * - DeferProp: always excluded (goes into deferredProps)
     * - OnceProp: resolved unless key is in X-Inertia-Except-Once-Props or in $except
     * - MergeProp: resolved (merge metadata is collected separately in build()).
     *
     * @param array<string, mixed> $props
     * @param string[]             $only       props to include (CSV from X-Inertia-Partial-Data)
     * @param string[]             $except     props to exclude (CSV from X-Inertia-Partial-Except)
     * @param string[]             $exceptOnce props to skip (CSV from X-Inertia-Except-Once-Props)
     * @param bool                 $isPartial  whether this is a partial reload request
     *
     * @return array<string, mixed>
     */
    public function resolveProps(
        array $props,
        array $only = [],
        array $except = [],
        array $exceptOnce = [],
        bool $isPartial = false,
    ): array {
        $resolved = [];
        /** @var string[] $alwaysKeys */
        $alwaysKeys = [];

        foreach ($props as $key => $value) {
            // DeferProp: excluded on full render and on partial when key is not explicitly in $only.
            // On a deferred-fetch partial reload (key in $only), resolve and include.
            // DeferProp::once(): skip resolution when client sends X-Inertia-Except-Once-Props
            // (client already has the cached value from the first deferred XHR).
            if ($value instanceof DeferProp) {
                if (!$isPartial || [] === $only || !\in_array($key, $only, true)) {
                    continue;
                }
                if ($value->isOnce() && \in_array($key, $exceptOnce, true)) {
                    continue;
                }
                $resolved[$key] = $value->resolve();
                continue;
            }

            // AlwaysProp: resolved on every render; bypasses partial $only/$except filters.
            if ($value instanceof AlwaysProp) {
                $resolved[$key] = $value->resolve();
                $alwaysKeys[] = $key;
                continue;
            }

            // OptionalProp: skip on full render; on partial, include only if explicitly in $only.
            // OptionalProp::once(): also skip when client sends X-Inertia-Except-Once-Props for this key
            // (client already has the cached value from a previous partial resolve).
            if ($value instanceof OptionalProp) {
                if (!$isPartial || [] === $only || !\in_array($key, $only, true)) {
                    continue;
                }
                // $except wins: do not resolve if key is in $except.
                if ([] !== $except && \in_array($key, $except, true)) {
                    continue;
                }
                if ($value->isOnce() && \in_array($key, $exceptOnce, true)) {
                    continue;
                }
                $resolved[$key] = $value->resolve();
                continue;
            }

            // OnceProp: skip if key is in $exceptOnce — but only on full XHR visits, not partial reloads.
            // (Matches Laravel: resolveOnceProperties() returns early when isPartial().)
            // Also skip (without resolving) if key is in $except or not in $only during partial reload.
            if ($value instanceof OnceProp) {
                if (!$isPartial && \in_array($key, $exceptOnce, true)) {
                    continue;
                }
                if ($isPartial && [] !== $except && \in_array($key, $except, true)) {
                    continue;
                }
                if ($isPartial && [] !== $only && !\in_array($key, $only, true)) {
                    continue;
                }
                $resolved[$key] = $value->resolve();
                continue;
            }

            // MergeProp: resolve the value, but skip (without resolving) if partial-reload filters exclude it.
            if ($value instanceof MergeProp) {
                if ($isPartial && [] !== $except && \in_array($key, $except, true)) {
                    continue;
                }
                if ($isPartial && [] !== $only && !\in_array($key, $only, true)) {
                    continue;
                }
                $resolved[$key] = $value->resolve();
                continue;
            }

            // ScrollProp: deferred ScrollProp behaves like DeferProp (only resolved on deferred XHR).
            // Non-deferred ScrollProp follows MergeProp filtering rules.
            if ($value instanceof ScrollProp) {
                if ($value->shouldDefer()) {
                    if (!$isPartial || [] === $only || !\in_array($key, $only, true)) {
                        continue;
                    }
                    $resolved[$key] = $value->resolve();
                    continue;
                }
                if ($isPartial && [] !== $except && \in_array($key, $except, true)) {
                    continue;
                }
                if ($isPartial && [] !== $only && !\in_array($key, $only, true)) {
                    continue;
                }
                $resolved[$key] = $value->resolve();
                continue;
            }

            // Closures: resolve.
            if ($value instanceof \Closure) {
                $resolved[$key] = $value();
                continue;
            }

            $resolved[$key] = $value;
        }

        if (!$isPartial) {
            return $resolved;
        }

        // Partial reload: apply $only/$except filtering. $except wins when both are set.
        $filtered = [];
        foreach ($resolved as $key => $value) {
            // errors and AlwaysProp keys are always included regardless of partial filters.
            if ('errors' === $key || \in_array($key, $alwaysKeys, true)) {
                $filtered[$key] = $value;
                continue;
            }

            // $except wins: skip any key in the except list.
            if ([] !== $except && \in_array($key, $except, true)) {
                continue;
            }

            // If $only is set and key is not in it, skip.
            if ([] !== $only && !\in_array($key, $only, true)) {
                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }

    /**
     * Collect merge/prepend/deepMerge/matchPropsOn arrays from the original props.
     *
     * MergeProp: only included when the key survived into resolved props (i.e. not filtered out).
     *
     * DeferProp + shouldMerge(): included on initial load regardless of resolved props, so the
     * client knows upfront that the deferred value will be merged when the XHR arrives.
     * On partial reloads, only included when the key was actually resolved.
     *
     * ScrollProp: same rule as DeferProp — on initial load always included (even when deferred);
     * on partial reloads only when resolved.
     *
     * Keys listed in X-Inertia-Reset are omitted entirely so the client replaces their value
     * instead of merging it (scrollProps still carries the reset flag).
     *
     * @param array<string, mixed> $rawProps          original props before resolution
     * @param array<string, mixed> $resolvedProps     props after resolution and filtering
     * @param string[]             $reset             keys from X-Inertia-Reset
     * @param string|null          $scrollMergeIntent value of X-Inertia-Infinite-Scroll-Merge-Intent header;
     *                                                when set, overrides the static $prepend flag on ScrollProp
     * @param bool                 $isPartial         whether this is a partial reload request
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>, 3: list<string>}
     */
    private function collectMergeArrays(array $rawProps, array $resolvedProps, array $reset = [], ?string $scrollMergeIntent = null, bool $isPartial = false): array
    {
        $mergeProps = [];
        $prependProps = [];
        $deepMergeProps = [];
        $matchPropsOn = [];

        foreach ($rawProps as $key => $prop) {
            if (\in_array($key, $reset, true)) {
                continue;
            }

            // DeferProp with merge semantics: announce merge metadata on initial load so the client
            // is ready to merge when the deferred XHR resolves. On partial reloads, only emit
            // when the key was actually fetched (i.e. appeared in X-Inertia-Partial-Data).
            if ($prop instanceof DeferProp) {
                if (!$prop->shouldMerge()) {
                    continue;
                }
                if ($isPartial && !\array_key_exists($key, $resolvedProps)) {
                    continue;
                }
                if ($prop->isDeep()) {
                    $deepMergeProps[] = $key;
                } elseif ($prop->mergesAtRoot()) {
                    if ($prop->isPrepend()) {
                        $prependProps[] = $key;
                    } else {
                        $mergeProps[] = $key;
                    }
                } else {
                    foreach ($prop->getAppendsAtPaths() as $path) {
                        $mergeProps[] = $key.'.'.$path;
                    }
                    foreach ($prop->getPrependsAtPaths() as $path) {
                        $prependProps[] = $key.'.'.$path;
                    }
                }
                foreach ($prop->getMatchOn() as $field) {
                    $matchPropsOn[] = $key.'.'.$field;
                }
                continue;
            }

            // MergeProp: only include when the key survived into resolved props.
            if ($prop instanceof MergeProp) {
                if (!\array_key_exists($key, $resolvedProps)) {
                    continue;
                }
                if ($prop->isDeep()) {
                    $deepMergeProps[] = $key;
                } elseif ($prop->mergesAtRoot()) {
                    if ($prop->isPrepend()) {
                        $prependProps[] = $key;
                    } else {
                        $mergeProps[] = $key;
                    }
                } else {
                    foreach ($prop->getAppendsAtPaths() as $path) {
                        $mergeProps[] = $key.'.'.$path;
                    }
                    foreach ($prop->getPrependsAtPaths() as $path) {
                        $prependProps[] = $key.'.'.$path;
                    }
                }
                foreach ($prop->getMatchOn() as $field) {
                    $matchPropsOn[] = $key.'.'.$field;
                }
                continue;
            }

            // ScrollProp: on initial load always include (deferred or not); on partial reloads
            // only include when the key was actually resolved.
            if ($prop instanceof ScrollProp) {
                if ($isPartial && !\array_key_exists($key, $resolvedProps)) {
                    continue;
                }
                if (!$isPartial && !\array_key_exists($key, $resolvedProps) && !$prop->shouldDefer()) {
                    continue;
                }
                $isPrepend = null !== $scrollMergeIntent
                    ? ('prepend' === $scrollMergeIntent)
                    : $prop->isPrepend();
                if ($isPrepend) {
                    $prependProps[] = $key;
                } else {
                    $mergeProps[] = $key;
                }
            }
        }

        return [$mergeProps, $prependProps, $deepMergeProps, $matchPropsOn];
    }

    /**
     * Collect scrollProps metadata for all ScrollProp instances that survived into resolved props.
     * The `reset` field is true when the client sent X-Inertia-Reset for this key.
     *
     * @param array<string, mixed> $rawProps
     * @param array<string, mixed> $resolvedProps
     * @param string[]             $reset         keys requested to reset via X-Inertia-Reset header
     *
     * @return array<string, array<string, mixed>>
     */
    private function collectScrollProps(array $rawProps, array $resolvedProps, array $reset = []): array
    {
        $scrollProps = [];
        foreach ($rawProps as $key => $prop) {
            if ($prop instanceof ScrollProp && \array_key_exists($key, $resolvedProps)) {
                $meta = $prop->toMetadata();
                $meta['reset'] = \in_array($key, $reset, true);
                $scrollProps[$key] = $meta;
            }
        }

        return $scrollProps;
    }

    /**
     * Build the canonical v3 page object array.
     * clearHistory and encryptHistory are omitted when false (v3 behaviour).
     *
     * @param array<string, mixed>                                 $resolvedProps
     * @param array<string, list<string>>                          $deferredProps  grouped deferred prop keys
     * @param list<string>                                         $mergeProps
     * @param list<string>                                         $prependProps
     * @param list<string>                                         $deepMergeProps
     * @param array<string, array{prop: string, expiresAt: mixed}> $onceProps      once-prop metadata
     * @param list<string>                                         $matchPropsOn   "propKey.fieldKey" entries for dedup
     * @param array<string, array<string, mixed>>                  $scrollProps    pagination metadata keyed by prop name (includes reset flag)
     * @param list<string>                                         $sharedPropKeys keys coming from Inertia::share(); emitted as top-level sharedProps when non-empty
     * @param array<string, mixed>                                 $flash          flash data emitted as top-level key (omitted when empty, matching inertia-laravel)
     *
     * @return array<string, mixed>
     */
    public function buildPageObject(
        string $component,
        array $resolvedProps,
        string $url,
        ?string $version,
        bool $clearHistory,
        bool $encryptHistory,
        array $deferredProps = [],
        array $mergeProps = [],
        array $prependProps = [],
        array $deepMergeProps = [],
        array $onceProps = [],
        array $matchPropsOn = [],
        array $scrollProps = [],
        array $sharedPropKeys = [],
        array $flash = [],
        bool $preserveFragment = false,
    ): array {
        $page = [
            'component' => $component,
            'props' => $resolvedProps,
            'url' => $url,
            'version' => $version,
            // v3: omitted when false, only present when true
            ...($clearHistory ? ['clearHistory' => true] : []),
            ...($encryptHistory ? ['encryptHistory' => true] : []),
            ...($preserveFragment ? ['preserveFragment' => true] : []),
        ];

        if ([] !== $deferredProps) {
            $page['deferredProps'] = $deferredProps;
        }

        if ([] !== $mergeProps) {
            $page['mergeProps'] = $mergeProps;
        }

        if ([] !== $prependProps) {
            $page['prependProps'] = $prependProps;
        }

        if ([] !== $deepMergeProps) {
            $page['deepMergeProps'] = $deepMergeProps;
        }

        if ([] !== $onceProps) {
            $page['onceProps'] = $onceProps;
        }

        if ([] !== $matchPropsOn) {
            $page['matchPropsOn'] = $matchPropsOn;
        }

        if ([] !== $scrollProps) {
            $page['scrollProps'] = $scrollProps;
        }

        if ([] !== $sharedPropKeys) {
            $page['sharedProps'] = $sharedPropKeys;
        }

        if ([] !== $flash) {
            $page['flash'] = $flash;
        }

        return $page;
    }

    /**
     * Normalize the full page object via the Symfony Normalizer.
     *
     * Merges sensible defaults (circular-reference handler, max-depth, empty-object
     * preservation) with the caller-supplied context and returns the normalized array
     * directly — no JSON encode/decode round-trip.
     *
     * @param array<string, mixed> $page
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function normalizePage(array $page, array $context): array
    {
        if (null === $this->normalizer) {
            throw new \LogicException('A serialization context was passed to Inertia::render() but no normalizer service is available. Ensure symfony/serializer is installed and framework.serializer is enabled in your config, or remove the context.');
        }

        if (!interface_exists(NormalizerInterface::class) || !$this->normalizer instanceof NormalizerInterface) {
            throw new \LogicException(\sprintf('The injected normalizer service must implement %s, got %s. Ensure symfony/serializer is installed and the Serializer service is properly configured.', NormalizerInterface::class, $this->normalizer::class));
        }

        $normalized = $this->normalizer->normalize($page, 'json', array_merge([
            'circular_reference_handler' => static fn (...$args): mixed => null,
            'preserve_empty_objects' => true,
            'enable_max_depth' => true,
        ], $context));

        if (!\is_array($normalized)) {
            throw new \RuntimeException('Normalizer did not return an array for the Inertia page object.');
        }

        return $normalized;
    }

    /**
     * @return string[]
     */
    private function parseCsv(string $header): array
    {
        if ('' === $header) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $header))));
    }
}
