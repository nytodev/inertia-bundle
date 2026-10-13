<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Testing;

use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fluent DSL for asserting Inertia.js page objects in tests.
 *
 * Works with both XHR responses (JSON body) and HTML first-visit responses
 * (<script data-page="app" type="application/json"> tag).
 *
 * Usage:
 *   AssertableInertiaPage::fromResponse($response)
 *       ->component('Users/Index')
 *       ->url('/users')
 *       ->has('users')
 *       ->where('users.0.name', 'John Doe')
 *       ->count('users', 5);
 */
final class AssertableInertiaPage
{
    /** @var string[] */
    private static array $paths = [];

    /** @var string[] */
    private static array $extensions = ['vue', 'jsx', 'tsx', 'js', 'ts', 'svelte'];

    private static bool $ensureExists = false;

    /** @param array<string, mixed> $page */
    private function __construct(private readonly array $page)
    {
    }

    /**
     * Configure the static file-existence check for component() assertions.
     * Call this in your test setUp() before asserting components.
     *
     * @param string[] $paths      Directories to scan for component files
     * @param string[] $extensions File extensions to look for (default: vue, jsx, tsx, js, ts, svelte)
     */
    public static function configure(
        array $paths = [],
        array $extensions = ['vue', 'jsx', 'tsx', 'js', 'ts', 'svelte'],
        bool $ensureExists = false,
    ): void {
        self::$paths = $paths;
        self::$extensions = $extensions;
        self::$ensureExists = $ensureExists;
    }

    /**
     * Creates an AssertableInertiaPage from a Symfony HTTP response.
     *
     * Supports:
     *  - XHR responses (X-Inertia: true header) — parses JSON body
     *  - HTML first-visit responses — extracts JSON from <script type="application/json"> tag
     */
    public static function fromResponse(Response $response): static
    {
        if ('true' === $response->headers->get('X-Inertia')) {
            /** @var array<string, mixed>|null $page */
            $page = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } else {
            $content = (string) $response->getContent();

            if (1 !== preg_match('/<script[^>]+type="application\/json"[^>]*>([^<]+)<\/script>/s', $content, $matches)) {
                Assert::fail('No Inertia page data found in response. Expected an X-Inertia header or a <script type="application/json"> tag.');
            }

            /** @var array<string, mixed>|null $page */
            $page = json_decode($matches[1], true, 512, \JSON_THROW_ON_ERROR);
        }

        if (!\is_array($page)) {
            Assert::fail('Inertia page data is not a valid JSON object.');
        }

        return new self($page);
    }

    /**
     * Asserts the Inertia component name.
     *
     * @param bool|null $shouldExist Override the static configure() setting:
     *                               true  = always validate file existence
     *                               false = always skip file existence check
     *                               null  = use configure() setting (default)
     */
    public function component(string $expected, ?bool $shouldExist = null): static
    {
        Assert::assertSame(
            $expected,
            $this->page['component'] ?? null,
            "Inertia component [{$expected}] does not match actual [{$this->page['component']}].",
        );

        $check = $shouldExist ?? self::$ensureExists;

        if ($check && [] !== self::$paths) {
            $this->assertComponentFileExists($expected);
        }

        return $this;
    }

    /**
     * Asserts the component file exists on disk relative to configured paths × extensions.
     */
    private function assertComponentFileExists(string $component): void
    {
        $normalized = str_replace('\\', '/', $component);

        foreach (self::$paths as $path) {
            foreach (self::$extensions as $ext) {
                if (file_exists($path.'/'.$normalized.'.'.$ext)) {
                    return;
                }
            }
        }

        Assert::fail(\sprintf('Inertia page component file [%s] does not exist.', $component));
    }

    /**
     * Asserts the page URL (relative path + query string).
     */
    public function url(string $expected): static
    {
        Assert::assertSame(
            $expected,
            $this->page['url'] ?? null,
            "Inertia URL [{$expected}] does not match actual [{$this->page['url']}].",
        );

        return $this;
    }

    /**
     * Asserts the asset version string (or null).
     */
    public function version(?string $expected): static
    {
        Assert::assertSame(
            $expected,
            $this->page['version'] ?? null,
            'Inertia version does not match expected value.',
        );

        return $this;
    }

    /**
     * Asserts a prop key exists (dot notation supported, e.g. "user.address.city").
     *
     * A prop with a null value still "exists" — has() passes.
     */
    public function has(string $key): static
    {
        $this->assertPropExists($key);

        return $this;
    }

    /**
     * Asserts a prop key is absent (dot notation supported).
     */
    public function missing(string $key): static
    {
        $this->assertPropMissing($key);

        return $this;
    }

    /**
     * Asserts a prop value equals the expected value (dot notation supported).
     *
     * When $expected is a Closure, it receives the actual value: returning false fails the assertion,
     * and a void closure can make its own assertions.
     *
     * @param mixed|\Closure(mixed):(bool|void) $expected
     */
    public function where(string $key, mixed $expected): static
    {
        $actual = $this->resolveProp($key);

        if ($expected instanceof \Closure) {
            $result = $expected($actual);

            // Only a bool is checked so that void closures with their own assertions keep working.
            if (\is_bool($result)) {
                Assert::assertTrue($result, "Inertia prop [{$key}] was marked as invalid using a closure.");
            }
        } else {
            Assert::assertSame(
                $expected,
                $actual,
                "Inertia prop [{$key}] does not match the expected value.",
            );
        }

        return $this;
    }

    /**
     * Asserts multiple prop key => value pairs.
     *
     * @param array<string, mixed> $props
     */
    public function whereAll(array $props): static
    {
        foreach ($props as $key => $value) {
            $this->where($key, $value);
        }

        return $this;
    }

    /**
     * Asserts a prop array has exactly N items (dot notation supported).
     */
    public function count(string $key, int $expected): static
    {
        $value = $this->resolveProp($key);

        Assert::assertIsArray($value, "Inertia prop [{$key}] is not an array.");
        Assert::assertCount($expected, $value, "Inertia prop [{$key}] does not have {$expected} item(s).");

        return $this;
    }

    /**
     * Dumps props for debugging (does not stop execution).
     */
    public function dump(): static
    {
        dump($this->page['props'] ?? $this->page);

        return $this;
    }

    /**
     * Dumps props and stops execution.
     */
    public function dd(): never // @codeCoverageIgnore
    {
        dd($this->page['props'] ?? $this->page); // @codeCoverageIgnore
    }

    // ── private ──────────────────────────────────────────────────────────────

    /**
     * Resolves a dot-notation key against props, returning the value.
     * Fails the test if any segment along the path is missing.
     */
    private function resolveProp(string $key): mixed
    {
        $segments = explode('.', $key);
        $value = $this->page['props'] ?? [];
        $path = '';

        foreach ($segments as $segment) {
            $path = '' === $path ? $segment : "{$path}.{$segment}";
            $arrayKey = is_numeric($segment) ? (int) $segment : $segment;

            if (!\is_array($value) || !\array_key_exists($arrayKey, $value)) {
                Assert::fail("Inertia prop [{$key}] is missing (failed at [{$path}]).");
            }

            $value = $value[$arrayKey];
        }

        return $value;
    }

    private function assertPropExists(string $key): void
    {
        $segments = explode('.', $key);
        $value = $this->page['props'] ?? [];
        $path = '';

        foreach ($segments as $segment) {
            $path = '' === $path ? $segment : "{$path}.{$segment}";
            $arrayKey = is_numeric($segment) ? (int) $segment : $segment;

            if (!\is_array($value)) {
                Assert::fail("Inertia prop [{$key}] is missing (failed at [{$path}]: parent is not an array).");
            }

            Assert::assertArrayHasKey($arrayKey, $value, "Inertia prop [{$key}] is missing (failed at [{$path}]).");
            $value = $value[$arrayKey];
        }
    }

    private function assertPropMissing(string $key): void
    {
        $segments = explode('.', $key);
        $value = $this->page['props'] ?? [];

        foreach ($segments as $index => $segment) {
            $arrayKey = is_numeric($segment) ? (int) $segment : $segment;

            if (!\is_array($value) || !\array_key_exists($arrayKey, $value)) {
                // path does not exist — key is missing as expected
                return;
            }

            if ($index === \count($segments) - 1) {
                // Last segment exists — should be missing
                Assert::assertArrayNotHasKey($arrayKey, $value, "Inertia prop [{$key}] is present but should be missing.");

                return;
            }

            $value = $value[$arrayKey];
        }

        Assert::fail("Inertia prop [{$key}] is present but should be missing."); // @codeCoverageIgnore
    }
}
