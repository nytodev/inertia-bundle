<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Unit\Response;

use Nytodev\InertiaBundle\Props\AlwaysProp;
use Nytodev\InertiaBundle\Props\DeferProp;
use Nytodev\InertiaBundle\Props\MergeProp;
use Nytodev\InertiaBundle\Props\OnceProp;
use Nytodev\InertiaBundle\Props\OptionalProp;
use Nytodev\InertiaBundle\Props\ScrollProp;
use Nytodev\InertiaBundle\Response\PropsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PropsResolverTest extends TestCase
{
    public function testOptionalPropIsSkippedOnFullRender(): void
    {
        [$props] = $this->resolve(['name' => new OptionalProp(static fn () => 'Tony'), 'title' => 'Hello']);

        self::assertArrayNotHasKey('name', $props);
        self::assertSame('Hello', $props['title']);
    }

    public function testOptionalPropIsResolvedWhenRequestedOnPartialReload(): void
    {
        [$props] = $this->resolve(['name' => new OptionalProp(static fn () => 'Tony')], $this->partial(only: 'name'));

        self::assertSame('Tony', $props['name']);
    }

    public function testOptionalPropInBothOnlyAndExceptIsNeverCalled(): void
    {
        $called = false;
        $optional = new OptionalProp(static function () use (&$called): string {
            $called = true;

            return 'Tony';
        });

        [$props] = $this->resolve(['name' => $optional, 'title' => 'Hello'], $this->partial(only: 'name', except: 'name'));

        self::assertFalse($called);
        self::assertArrayNotHasKey('name', $props);
    }

    /**
     * Deliberate divergence from inertia-laravel 3.x, which resolves optional props on except-only partial reloads.
     */
    public function testOptionalPropOnExceptOnlyPartialReloadIsNeverCalled(): void
    {
        $called = false;
        $optional = new OptionalProp(static function () use (&$called): string {
            $called = true;

            return 'lazy-value';
        });

        [$props] = $this->resolve(['lazy' => $optional, 'eager' => 'value', 'other' => 'x'], $this->partial(except: 'eager'));

        self::assertFalse($called);
        self::assertSame(['other' => 'x'], $props);
    }

    public function testDeferredScrollPropOnExceptOnlyPartialReloadIsNeverCalled(): void
    {
        $called = false;
        $scroll = (new ScrollProp(static function () use (&$called): array {
            $called = true;

            return [];
        }))->defer();

        [$props] = $this->resolve(['posts' => $scroll, 'other' => 'x'], $this->partial(except: 'unrelated'));

        self::assertFalse($called);
        self::assertSame(['other' => 'x'], $props);
    }

    public function testDeferPropIsExcludedOnFullRender(): void
    {
        [$props] = $this->resolve(['users' => new DeferProp(static fn () => []), 'title' => 'Hello']);

        self::assertArrayNotHasKey('users', $props);
        self::assertSame('Hello', $props['title']);
    }

    public function testDeferPropIsExcludedOnPartialReloadWhenNotRequested(): void
    {
        [$props] = $this->resolve(['users' => new DeferProp(static fn () => []), 'title' => 'Hello'], $this->partial(only: 'title'));

        self::assertArrayNotHasKey('users', $props);
        self::assertSame('Hello', $props['title']);
    }

    public function testDeferPropIsResolvedWhenRequested(): void
    {
        [$props] = $this->resolve(['users' => new DeferProp(static fn () => ['a', 'b']), 'title' => 'Hello'], $this->partial(only: 'users'));

        self::assertSame(['users' => ['a', 'b']], $props);
    }

    public function testErrorsPropIsAlwaysIncludedOnPartialReload(): void
    {
        [$props] = $this->resolve(['errors' => ['name' => 'required'], 'title' => 'Hello', 'other' => 'x'], $this->partial(only: 'title'));

        self::assertSame(['errors' => ['name' => 'required'], 'title' => 'Hello'], $props);
    }

    public function testOncePropIsResolvedNormally(): void
    {
        [$props] = $this->resolve(['name' => new OnceProp(static fn () => 'Tony')]);

        self::assertSame('Tony', $props['name']);
    }

    public function testOncePropIsSkippedWhenClientAlreadyHasIt(): void
    {
        [$props] = $this->resolve(
            ['name' => new OnceProp(static fn () => 'Tony'), 'title' => 'Hello'],
            ['X-Inertia-Except-Once-Props' => 'name'],
        );

        self::assertArrayNotHasKey('name', $props);
        self::assertSame('Hello', $props['title']);
    }

    public function testOncePropInExceptIsNeverCalled(): void
    {
        $called = false;
        $once = new OnceProp(static function () use (&$called): string {
            $called = true;

            return 'Tony';
        });

        [$props] = $this->resolve(['name' => $once, 'title' => 'Hello'], $this->partial(only: 'name,title', except: 'name'));

        self::assertFalse($called);
        self::assertSame(['title' => 'Hello'], $props);
    }

    public function testClosureIsResolved(): void
    {
        [$props] = $this->resolve(['count' => static fn () => 42]);

        self::assertSame(42, $props['count']);
    }

    public function testExceptWinsOverOnly(): void
    {
        [$props] = $this->resolve(['a' => 1, 'b' => 2, 'c' => 3], $this->partial(only: 'a,b', except: 'b'));

        self::assertSame(['a' => 1], $props);
    }

    public function testMergePropIsResolvedNormally(): void
    {
        [$props] = $this->resolve(['items' => new MergeProp(static fn () => [1, 2, 3])]);

        self::assertSame([1, 2, 3], $props['items']);
    }

    public function testAlwaysPropIsResolvedOnFullRender(): void
    {
        [$props] = $this->resolve(['always' => new AlwaysProp(static fn () => 'always-value'), 'title' => 'Hello']);

        self::assertSame(['always' => 'always-value', 'title' => 'Hello'], $props);
    }

    public function testAlwaysPropIsIncludedWhenNotInOnly(): void
    {
        [$props] = $this->resolve(
            ['always' => new AlwaysProp(static fn () => 'always-value'), 'title' => 'Hello', 'other' => 'x'],
            $this->partial(only: 'title'),
        );

        self::assertSame(['always' => 'always-value', 'title' => 'Hello'], $props);
    }

    public function testAlwaysPropIsIncludedEvenWhenInExcept(): void
    {
        [$props] = $this->resolve(
            ['always' => new AlwaysProp(static fn () => 'always-value'), 'other' => 'x', 'ignored' => 'y'],
            $this->partial(only: 'other', except: 'always'),
        );

        self::assertSame(['always' => 'always-value', 'other' => 'x'], $props);
    }

    public function testAlwaysPropClosureIsCalledExactlyOnce(): void
    {
        $callCount = 0;
        $this->resolve(['always' => new AlwaysProp(static function () use (&$callCount): string {
            ++$callCount;

            return 'v';
        })]);

        self::assertSame(1, $callCount);
    }

    public function testPartialHeadersAreIgnoredWhenComponentDoesNotMatch(): void
    {
        [$props] = $this->resolve(['a' => 1, 'b' => 2], [
            'X-Inertia-Partial-Component' => 'Other',
            'X-Inertia-Partial-Data' => 'a',
        ]);

        self::assertSame(['a' => 1, 'b' => 2], $props);
    }

    public function testClosureNotRequestedOnPartialReloadIsNeverCalled(): void
    {
        $called = false;
        [$props] = $this->resolve([
            'expensive' => static function () use (&$called): string {
                $called = true;

                return 'E';
            },
            'reviews' => new DeferProp(static fn () => ['a']),
        ], $this->partial(only: 'reviews'));

        self::assertFalse($called);
        self::assertSame(['reviews' => ['a']], $props);
    }

    public function testClosureInExceptIsNeverCalled(): void
    {
        $called = false;
        [$props] = $this->resolve([
            'expensive' => static function () use (&$called): string {
                $called = true;

                return 'E';
            },
            'cheap' => 'x',
        ], $this->partial(except: 'expensive'));

        self::assertFalse($called);
        self::assertSame(['cheap' => 'x'], $props);
    }

    public function testNestedClosuresAreResolved(): void
    {
        [$props] = $this->resolve([
            'auth' => ['user' => static fn () => ['name' => 'Tony']],
            'stats' => ['count' => static fn () => 42],
        ]);

        self::assertSame(['auth' => ['user' => ['name' => 'Tony']], 'stats' => ['count' => 42]], $props);
    }

    public function testClosuresInsideClosureResultAreResolved(): void
    {
        [$props] = $this->resolve(['auth' => static fn () => ['user' => static fn () => 'Tony']]);

        self::assertSame(['auth' => ['user' => 'Tony']], $props);
    }

    public function testClosureReturningPropTypeIsUnwrapped(): void
    {
        $resolver = $this->makeResolver();
        $props = $resolver->resolve(['comments' => static fn () => new DeferProp(static fn () => ['c1'], 'sidebar')]);

        self::assertSame([], $props);
        self::assertSame(['sidebar' => ['comments']], $resolver->deferredProps());
    }

    public function testNestedClosureIsCalledExactlyOnce(): void
    {
        $callCount = 0;
        $this->resolve(['auth' => ['user' => static function () use (&$callCount): string {
            ++$callCount;

            return 'Tony';
        }]]);

        self::assertSame(1, $callCount);
    }

    public function testNestedOptionalPropIsSkippedOnFullRender(): void
    {
        [$props] = $this->resolve(['auth' => ['user' => 'Tony', 'permissions' => new OptionalProp(static fn () => ['read'])]]);

        self::assertSame(['auth' => ['user' => 'Tony']], $props);
    }

    public function testNestedOptionalPropIsResolvedWhenParentIsRequested(): void
    {
        [$props] = $this->resolve(
            ['auth' => ['user' => 'Tony', 'permissions' => new OptionalProp(static fn () => ['read'])], 'other' => 'x'],
            $this->partial(only: 'auth'),
        );

        self::assertSame(['auth' => ['user' => 'Tony', 'permissions' => ['read']]], $props);
    }

    public function testNestedMergePropEmitsDotPathMetadata(): void
    {
        $resolver = $this->makeResolver();
        $resolver->resolve(['feed' => [
            'posts' => new MergeProp(static fn () => [['id' => 1]], matchOn: 'id'),
            'notifications' => new MergeProp(static fn () => [], prepend: true),
            'tree' => new MergeProp(static fn () => ['a' => 1], deep: true),
        ]]);

        self::assertSame(['feed.posts'], $resolver->mergeProps());
        self::assertSame(['feed.notifications'], $resolver->prependProps());
        self::assertSame(['feed.tree'], $resolver->deepMergeProps());
        self::assertSame(['feed.posts.id'], $resolver->matchPropsOn());
    }

    public function testNestedDeferPropEmitsDotPathInDeferredGroup(): void
    {
        $resolver = $this->makeResolver();
        $props = $resolver->resolve(['feed' => ['comments' => new DeferProp(static fn () => ['c1'])]]);

        self::assertSame(['feed' => []], $props);
        self::assertSame(['default' => ['feed.comments']], $resolver->deferredProps());
    }

    public function testNestedOncePropEmitsDotPathMetadata(): void
    {
        $resolver = $this->makeResolver();
        $resolver->resolve(['config' => ['locale' => new OnceProp(static fn () => 'en')]]);

        self::assertSame(['config.locale' => ['prop' => 'config.locale', 'expiresAt' => null]], $resolver->onceProps());
    }

    public function testNestedScrollPropEmitsDotPathMetadata(): void
    {
        $resolver = $this->makeResolver();
        $resolver->resolve(['feed' => ['posts' => new ScrollProp(static fn () => [['id' => 1]], nextPage: 2)]]);

        self::assertSame(['feed.posts'], $resolver->mergeProps());
        self::assertArrayHasKey('feed.posts', $resolver->scrollProps());
        self::assertFalse($resolver->scrollProps()['feed.posts']['reset']);
    }

    public function testResetOnNestedPathExcludesItFromMergeMetadata(): void
    {
        $headers = $this->partial(only: 'feed.posts');
        $headers['X-Inertia-Reset'] = 'feed.posts';
        $resolver = $this->makeResolver($headers);
        $props = $resolver->resolve(['feed' => ['posts' => new MergeProp(static fn () => [['id' => 1]])]]);

        self::assertSame(['feed' => ['posts' => [['id' => 1]]]], $props);
        self::assertSame([], $resolver->mergeProps());
    }

    public function testOnlyDotPathReturnsRequestedLeafAndItsAncestors(): void
    {
        [$props] = $this->resolve(
            ['auth' => ['user' => 'Tony', 'permissions' => ['read']], 'other' => 'x'],
            $this->partial(only: 'auth.user'),
        );

        self::assertSame(['auth' => ['user' => 'Tony']], $props);
    }

    public function testOnlyDotPathDoesNotCallSiblingClosures(): void
    {
        $called = false;
        [$props] = $this->resolve(['auth' => [
            'user' => 'Tony',
            'permissions' => static function () use (&$called): array {
                $called = true;

                return ['read'];
            },
        ]], $this->partial(only: 'auth.user'));

        self::assertFalse($called);
        self::assertSame(['auth' => ['user' => 'Tony']], $props);
    }

    public function testOnlyDotPathThroughClosureReturnsWholeClosureResult(): void
    {
        [$props] = $this->resolve(
            ['auth' => static fn () => ['user' => 'Tony', 'permissions' => ['read']]],
            $this->partial(only: 'auth.user'),
        );

        self::assertSame(['auth' => ['user' => 'Tony', 'permissions' => ['read']]], $props);
    }

    public function testExceptDotPathRemovesOnlyThatSubtree(): void
    {
        [$props] = $this->resolve(
            ['auth' => ['user' => 'Tony', 'token' => 'secret'], 'other' => 'x'],
            $this->partial(only: 'auth', except: 'auth.token'),
        );

        self::assertSame(['auth' => ['user' => 'Tony']], $props);
    }

    public function testNestedAlwaysPropIsIncludedWhenParentIsTraversed(): void
    {
        [$props] = $this->resolve(
            ['auth' => ['user' => 'Tony', 'flags' => new AlwaysProp(static fn () => ['beta']), 'other' => 'x']],
            $this->partial(only: 'auth.user'),
        );

        self::assertSame(['auth' => ['user' => 'Tony', 'flags' => ['beta']]], $props);
    }

    public function testPartialMetadataOnlyCoversIncludedPaths(): void
    {
        $resolver = $this->makeResolver($this->partial(only: 'feed.posts'));
        $resolver->resolve(['feed' => [
            'posts' => new MergeProp(static fn () => [1]),
            'likes' => new MergeProp(static fn () => [2]),
        ]]);

        self::assertSame(['feed.posts'], $resolver->mergeProps());
    }

    /**
     * @param array<string, mixed>  $props
     * @param array<string, string> $headers
     *
     * @return array{0: array<string, mixed>, 1: PropsResolver}
     */
    private function resolve(array $props, array $headers = []): array
    {
        $resolver = $this->makeResolver($headers);

        return [$resolver->resolve($props), $resolver];
    }

    /**
     * @param array<string, string> $headers
     */
    private function makeResolver(array $headers = []): PropsResolver
    {
        $request = Request::create('/home');
        $request->headers->set('X-Inertia', 'true');
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return new PropsResolver($request, 'Home');
    }

    /**
     * @return array<string, string>
     */
    private function partial(string $only = '', string $except = ''): array
    {
        return array_filter([
            'X-Inertia-Partial-Component' => 'Home',
            'X-Inertia-Partial-Data' => $only,
            'X-Inertia-Partial-Except' => $except,
        ]);
    }
}
