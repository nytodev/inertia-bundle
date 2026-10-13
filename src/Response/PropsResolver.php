<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Response;

use Nytodev\InertiaBundle\Props\AlwaysProp;
use Nytodev\InertiaBundle\Props\DeferProp;
use Nytodev\InertiaBundle\Props\MergeProp;
use Nytodev\InertiaBundle\Props\OnceProp;
use Nytodev\InertiaBundle\Props\OptionalProp;
use Nytodev\InertiaBundle\Props\ScrollProp;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves the props of one render in a single recursive pass (inertia-laravel 3.x PropsResolver):
 * partial-reload filtering runs before any closure is called, and metadata uses dot paths.
 * Create one instance per render — it accumulates that render's metadata.
 */
final class PropsResolver
{
    private readonly bool $isPartial;

    /** @var list<string> */
    private readonly array $only;

    /** @var list<string> */
    private readonly array $except;

    /** @var list<string> */
    private readonly array $reset;

    /** @var list<string> */
    private readonly array $loadedOnceProps;

    private readonly ?string $scrollMergeIntent;

    /** @var array<string, list<string>> */
    private array $deferredProps = [];

    /** @var list<string> */
    private array $mergeProps = [];

    /** @var list<string> */
    private array $prependProps = [];

    /** @var list<string> */
    private array $deepMergeProps = [];

    /** @var list<string> */
    private array $matchPropsOn = [];

    /** @var array<string, array<string, mixed>> */
    private array $scrollProps = [];

    /** @var array<string, array{prop: string, expiresAt: int|null}> */
    private array $onceProps = [];

    public function __construct(Request $request, string $component)
    {
        $this->only = $this->parseCsv($request->headers->get('X-Inertia-Partial-Data'));
        $this->except = $this->parseCsv($request->headers->get('X-Inertia-Partial-Except'));
        $this->reset = $this->parseCsv($request->headers->get('X-Inertia-Reset'));
        $this->loadedOnceProps = $this->parseCsv($request->headers->get('X-Inertia-Except-Once-Props'));
        $this->scrollMergeIntent = $request->headers->get('X-Inertia-Infinite-Scroll-Merge-Intent');
        $this->isPartial = ([] !== $this->only || [] !== $this->except)
            && $component === $request->headers->get('X-Inertia-Partial-Component');
    }

    /**
     * @param array<array-key, mixed> $props
     *
     * @return array<array-key, mixed>
     */
    public function resolve(array $props): array
    {
        return $this->resolveLevel($this->unpackDotProps($props), '', false);
    }

    /**
     * @return array<string, list<string>>
     */
    public function deferredProps(): array
    {
        return $this->deferredProps;
    }

    /**
     * @return list<string>
     */
    public function mergeProps(): array
    {
        return $this->mergeProps;
    }

    /**
     * @return list<string>
     */
    public function prependProps(): array
    {
        return $this->prependProps;
    }

    /**
     * @return list<string>
     */
    public function deepMergeProps(): array
    {
        return $this->deepMergeProps;
    }

    /**
     * @return list<string>
     */
    public function matchPropsOn(): array
    {
        return $this->matchPropsOn;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function scrollProps(): array
    {
        return $this->scrollProps;
    }

    /**
     * @return array<string, array{prop: string, expiresAt: int|null}>
     */
    public function onceProps(): array
    {
        return $this->onceProps;
    }

    /**
     * @param array<array-key, mixed> $props
     *
     * @return array<array-key, mixed>
     */
    private function resolveLevel(array $props, string $prefix, bool $parentWasResolved): array
    {
        $resolved = [];

        foreach ($props as $key => $prop) {
            $path = '' === $prefix ? (string) $key : $prefix.'.'.$key;

            // Emitted even when the prop is filtered out, so the client keeps its cached copy.
            $this->collectOnceMetadata($prop, $path);

            if (!$this->isIncludedInPartial($prop, $path, $parentWasResolved)) {
                continue;
            }

            [$isIncluded, $value] = $this->resolveEntry($prop, $path);

            if (!$isIncluded) {
                continue;
            }

            // Children of a value produced by a closure or prop object bypass partial filtering, as do validation errors.
            $resolved[$key] = \is_array($value)
                ? $this->resolveLevel($value, $path, $parentWasResolved || !\is_array($prop) || 'errors' === $path)
                : $value;
        }

        return $resolved;
    }

    /**
     * Nests top-level keys such as `auth.user.permissions` (inertia-laravel unpackDotProps). A closure on an
     * intermediate segment is called so the value can be nested into its result; the leaf is left unresolved
     * so that partial filtering still applies to it.
     *
     * @param array<array-key, mixed> $props
     *
     * @return array<array-key, mixed>
     */
    private function unpackDotProps(array $props): array
    {
        foreach ($props as $key => $value) {
            if (!\is_string($key) || !str_contains($key, '.')) {
                continue;
            }

            unset($props[$key]);
            $segments = explode('.', $key);
            $leaf = array_pop($segments);
            $current = &$props;

            foreach ($segments as $segment) {
                if (($current[$segment] ?? null) instanceof \Closure) {
                    $current[$segment] = $current[$segment]();
                }
                if (!\is_array($current[$segment] ?? null)) {
                    $current[$segment] = [];
                }
                $current = &$current[$segment];
            }

            $current[$leaf] = $value;
            unset($current);
        }

        return $props;
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function resolveEntry(mixed $prop, string $path): array
    {
        [$isIncluded, $value] = $this->resolvePropValue($prop, $path);

        // A closure or prop object may return another prop object, which must go through its own rules and metadata.
        if ($isIncluded && $value !== $prop && $this->isPropType($value)) {
            $this->collectOnceMetadata($value, $path);

            return $this->resolveEntry($value, $path);
        }

        return [$isIncluded, $value];
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function resolvePropValue(mixed $prop, string $path): array
    {
        if ($prop instanceof DeferProp) {
            if (!$this->isPartial) {
                // The client keeps its cached copy, so it must not be asked to fetch it again.
                if (!$this->wasAlreadyLoadedByClient($prop, $path)) {
                    $this->deferredProps[$prop->getGroup()][] = $path;
                }
                if ($prop->shouldMerge()) {
                    $this->collectMergeMetadata($prop, $path);
                }

                return [false, null];
            }

            if ($prop->shouldMerge()) {
                $this->collectMergeMetadata($prop, $path);
            }

            return [true, $prop->resolve()];
        }

        if ($prop instanceof OptionalProp) {
            if (!$this->isPartial) {
                return [false, null];
            }

            return [true, $prop->resolve()];
        }

        if ($prop instanceof ScrollProp) {
            if ($prop->shouldDefer() && !$this->isPartial) {
                $this->deferredProps[$prop->getGroup()][] = $path;
                $this->collectScrollMergeMetadata($prop, $path);

                return [false, null];
            }

            $this->collectScrollMergeMetadata($prop, $path);
            $this->scrollProps[$path] = [...$prop->toMetadata(), 'reset' => \in_array($path, $this->reset, true)];

            return [true, $prop->resolve()];
        }

        if ($prop instanceof OnceProp) {
            if (!$this->isPartial && $this->wasAlreadyLoadedByClient($prop, $path)) {
                return [false, null];
            }

            return [true, $prop->resolve()];
        }

        if ($prop instanceof MergeProp) {
            $this->collectMergeMetadata($prop, $path);

            return [true, $prop->resolve()];
        }

        if ($prop instanceof AlwaysProp) {
            return [true, $prop->resolve()];
        }

        if ($prop instanceof \Closure) {
            return [true, $prop()];
        }

        return [true, $prop];
    }

    private function isIncludedInPartial(mixed $prop, string $path, bool $parentWasResolved): bool
    {
        if (!$this->isPartial || $parentWasResolved || $prop instanceof AlwaysProp || 'errors' === $path) {
            return true;
        }

        if ([] !== $this->only && !$this->matchesAny($this->only, $path) && !$this->leadsToOnly($path)) {
            return false;
        }

        return !$this->matchesAny($this->except, $path);
    }

    private function wasAlreadyLoadedByClient(DeferProp|OnceProp $prop, string $path): bool
    {
        if ($prop instanceof OnceProp) {
            return !$prop->isFresh() && \in_array($prop->getAlias() ?? $path, $this->loadedOnceProps, true);
        }

        return $prop->isOnce() && \in_array($path, $this->loadedOnceProps, true);
    }

    /**
     * @param list<string> $filters
     */
    private function matchesAny(array $filters, string $path): bool
    {
        foreach ($filters as $filter) {
            if ($path === $filter || str_starts_with($path, $filter.'.')) {
                return true;
            }
        }

        return false;
    }

    private function leadsToOnly(string $path): bool
    {
        foreach ($this->only as $filter) {
            if (str_starts_with($filter, $path.'.')) {
                return true;
            }
        }

        return false;
    }

    private function collectMergeMetadata(DeferProp|MergeProp $prop, string $path): void
    {
        if (\in_array($path, $this->reset, true)) {
            return;
        }

        if ($prop->isDeep()) {
            $this->deepMergeProps[] = $path;
        } elseif ($prop->mergesAtRoot()) {
            if ($prop->isPrepend()) {
                $this->prependProps[] = $path;
            } else {
                $this->mergeProps[] = $path;
            }
        } else {
            foreach ($prop->getAppendsAtPaths() as $appendPath) {
                $this->mergeProps[] = $path.'.'.$appendPath;
            }
            foreach ($prop->getPrependsAtPaths() as $prependPath) {
                $this->prependProps[] = $path.'.'.$prependPath;
            }
        }

        foreach ($prop->getMatchOn() as $field) {
            $this->matchPropsOn[] = $path.'.'.$field;
        }
    }

    private function collectScrollMergeMetadata(ScrollProp $prop, string $path): void
    {
        if (\in_array($path, $this->reset, true)) {
            return;
        }

        $isPrepend = null !== $this->scrollMergeIntent
            ? 'prepend' === $this->scrollMergeIntent
            : $prop->isPrepend();

        if ($isPrepend) {
            $this->prependProps[] = $path;
        } else {
            $this->mergeProps[] = $path;
        }
    }

    private function collectOnceMetadata(mixed $prop, string $path): void
    {
        if ($prop instanceof OnceProp) {
            $expiresAt = $prop->getExpiresAt();
            $this->onceProps[$prop->getAlias() ?? $path] = [
                'prop' => $path,
                // Milliseconds: the client compares it with Date.now().
                'expiresAt' => null !== $expiresAt ? $expiresAt->getTimestamp() * 1000 : null,
            ];

            return;
        }

        if (($prop instanceof DeferProp || $prop instanceof OptionalProp) && $prop->isOnce()) {
            $this->onceProps[$path] = ['prop' => $path, 'expiresAt' => null];
        }
    }

    private function isPropType(mixed $value): bool
    {
        return $value instanceof AlwaysProp
            || $value instanceof DeferProp
            || $value instanceof MergeProp
            || $value instanceof OnceProp
            || $value instanceof OptionalProp
            || $value instanceof ScrollProp;
    }

    /**
     * @return list<string>
     */
    private function parseCsv(?string $header): array
    {
        if (null === $header || '' === $header) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $header))));
    }
}
