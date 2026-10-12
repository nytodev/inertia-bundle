<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Response;

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
        $resolver = new PropsResolver($request, $component);
        $resolved = $resolver->resolve($props);

        // Empty errors must serialize as {} — an empty PHP array would encode as [] (inertia-laravel: (object) []).
        if (!\array_key_exists('errors', $resolved) || [] === $resolved['errors']) {
            $resolved['errors'] = new \stdClass();
        }

        $page = $this->buildPageObject(
            $component,
            $resolved,
            $url,
            $version,
            $clearHistory,
            $encryptHistory,
            $resolver->deferredProps(),
            $resolver->mergeProps(),
            $resolver->prependProps(),
            $resolver->deepMergeProps(),
            $resolver->onceProps(),
            $resolver->matchPropsOn(),
            $resolver->scrollProps(),
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
     * Build the canonical v3 page object array.
     * clearHistory and encryptHistory are omitted when false (v3 behaviour).
     *
     * @param array<string, mixed>                                                  $resolvedProps
     * @param array<string, list<string>>                                           $deferredProps  grouped deferred prop keys
     * @param list<string>                                                          $mergeProps
     * @param list<string>                                                          $prependProps
     * @param list<string>                                                          $deepMergeProps
     * @param array<string, array{prop: string, expiresAt: int|null, fresh?: true}> $onceProps      once-prop metadata
     * @param list<string>                                                          $matchPropsOn   "propKey.fieldKey" entries for dedup
     * @param array<string, array<string, mixed>>                                   $scrollProps    pagination metadata keyed by prop name (includes reset flag)
     * @param list<string>                                                          $sharedPropKeys keys coming from Inertia::share(); emitted as top-level sharedProps when non-empty
     * @param array<string, mixed>                                                  $flash          flash data emitted as top-level key (omitted when empty, matching inertia-laravel)
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
}
