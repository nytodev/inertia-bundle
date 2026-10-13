<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional;

use Nytodev\InertiaBundle\Props\AlwaysProp;
use Nytodev\InertiaBundle\Props\DeferProp;
use Nytodev\InertiaBundle\Props\MergeProp;
use Nytodev\InertiaBundle\Props\OnceProp;
use Nytodev\InertiaBundle\Props\OptionalProp;
use Nytodev\InertiaBundle\Props\ScrollProp;
use Nytodev\InertiaBundle\Service\Inertia;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class TestController
{
    public function __construct(private readonly Inertia $inertia)
    {
    }

    public function index(): Response
    {
        return $this->inertia->render('TestComponent', ['foo' => 'bar']);
    }

    public function redirect(): Response
    {
        return new RedirectResponse('/test', 302);
    }

    public function shared(): Response
    {
        return $this->inertia->render('TestComponent', ['local' => 'value']);
    }

    public function partial(): Response
    {
        return $this->inertia->render('TestComponent', [
            'foo' => 'bar',
            'baz' => 'qux',
            'errors' => [],
        ]);
    }

    public function optional(): Response
    {
        return $this->inertia->render('TestComponent', [
            'eager' => 'value',
            'lazy' => new OptionalProp(static fn () => 'lazy-value'),
            'errors' => [],
        ]);
    }

    public function merge(): Response
    {
        return $this->inertia->render('TestComponent', [
            'merge_a' => new MergeProp(static fn () => [1, 2]),
            'merge_b' => new MergeProp(static fn () => [3, 4]),
        ]);
    }

    public function matchPropsOn(): Response
    {
        return $this->inertia->render('TestComponent', [
            'merge_a' => new MergeProp(static fn () => [['id' => 1], ['id' => 2]], matchOn: 'id'),
            'merge_b' => new MergeProp(static fn () => [['id' => 3], ['id' => 4]]),
        ]);
    }

    public function matchPropsOnMulti(): Response
    {
        return $this->inertia->render('TestComponent', [
            'merge_a' => $this->inertia->merge(static fn () => [], matchOn: ['id', 'uuid']),
        ]);
    }

    public function matchPropsOnDeep(): Response
    {
        return $this->inertia->render('TestComponent', [
            'merge_a' => $this->inertia->merge(static fn () => [], matchOn: 'id', deep: true),
        ]);
    }

    public function defer(): Response
    {
        return $this->inertia->render('TestComponent', [
            'eager' => 'eager-value',
            'deferred' => new DeferProp(static fn () => 'deferred-value'),
        ]);
    }

    public function always(): Response
    {
        return $this->inertia->render('TestComponent', [
            'always' => new AlwaysProp(static fn () => 'always-value'),
            'regular' => 'regular-value',
        ]);
    }

    public function onceProp(): Response
    {
        return $this->inertia->render('TestComponent', [
            'plans' => new OnceProp(static fn () => ['plan_a', 'plan_b']),
            'regular' => 'regular-value',
        ]);
    }

    public function clearHistory(): Response
    {
        $this->inertia->clearHistory();

        return $this->inertia->render('TestComponent', ['foo' => 'bar']);
    }

    public function clearHistoryRedirect(): Response
    {
        $this->inertia->clearHistory();

        return new RedirectResponse('/test', 302);
    }

    public function encryptHistory(): Response
    {
        $this->inertia->encryptHistory();

        return $this->inertia->render('TestComponent', ['foo' => 'bar']);
    }

    public function preserveFragment(): Response
    {
        $this->inertia->preserveFragment();

        return $this->inertia->render('TestComponent', ['foo' => 'bar']);
    }

    public function preserveFragmentRedirect(): Response
    {
        $this->inertia->preserveFragment();

        return new RedirectResponse('/test', 302);
    }

    public function redirectWithFragment(): Response
    {
        return new RedirectResponse('/test#section', 302);
    }

    public function scrollProps(): Response
    {
        return $this->inertia->render('TestComponent', [
            'posts' => new ScrollProp(static fn () => [['id' => 1], ['id' => 2]], nextPage: 2),
        ]);
    }

    public function scrollPropsPrepend(): Response
    {
        return $this->inertia->render('TestComponent', [
            'posts' => new ScrollProp(static fn () => [['id' => 3], ['id' => 4]], nextPage: 1, prepend: true),
        ]);
    }

    public function scrollPropsIntent(): Response
    {
        return $this->inertia->render('TestComponent', [
            'posts' => new ScrollProp(static fn () => [['id' => 1]], nextPage: 2, prepend: false),
        ]);
    }

    public function scrollPropsIntentPrepend(): Response
    {
        return $this->inertia->render('TestComponent', [
            'posts' => new ScrollProp(static fn () => [['id' => 1]], nextPage: 2, prepend: true),
        ]);
    }

    public function scrollPropsFull(): Response
    {
        return $this->inertia->render('TestComponent', [
            'posts' => new ScrollProp(
                static fn () => [['id' => 5]],
                pageName: 'page',
                nextPage: 3,
                previousPage: 1,
                currentPage: 2,
            ),
        ]);
    }

    public function flashDirect(): Response
    {
        $this->inertia->flash('status', 'saved');

        return $this->inertia->render('TestComponent', ['foo' => 'bar']);
    }

    public function flashTarget(): Response
    {
        return $this->inertia->render('TestComponent', ['foo' => 'bar']);
    }

    public function flashRedirect(): Response
    {
        $this->inertia->flash('status', 'saved');

        return new RedirectResponse('/test/flash-target', 302);
    }

    public function location(): Response
    {
        return $this->inertia->location('https://example.com/payment');
    }

    public function locationInternal(): Response
    {
        return $this->inertia->location('/other-page');
    }

    public function deferMerge(): Response
    {
        return $this->inertia->render('TestComponent', [
            'eager' => 'eager-value',
            'deferred' => (new DeferProp(static fn () => 'deferred-value'))->merge(),
        ]);
    }

    public function deferDeepMerge(): Response
    {
        return $this->inertia->render('TestComponent', [
            'eager' => 'eager-value',
            'deferred' => (new DeferProp(static fn () => [['id' => 1]]))->deepMerge(),
        ]);
    }

    public function deferMergeMatchOn(): Response
    {
        return $this->inertia->render('TestComponent', [
            'eager' => 'eager-value',
            'deferred' => (new DeferProp(static fn () => [['id' => 1]]))->merge()->matchOn('id'),
        ]);
    }

    public function scrollDefer(): Response
    {
        return $this->inertia->render('TestComponent', [
            'posts' => (new ScrollProp(static fn () => [['id' => 1]], nextPage: 2))->defer(),
        ]);
    }

    public function scrollDeferGroup(): Response
    {
        return $this->inertia->render('TestComponent', [
            'posts' => (new ScrollProp(static fn () => [['id' => 1]], nextPage: 2))->defer('sidebar'),
        ]);
    }

    public function validationErrors(): Response
    {
        $this->inertia->errors(['email' => 'Invalid email']);

        return $this->inertia->render('TestComponent', ['foo' => 'bar']);
    }

    public function validationErrorsTarget(): Response
    {
        return $this->inertia->render('TestComponent', ['foo' => 'bar']);
    }

    public function validationErrorsRedirect(): Response
    {
        $this->inertia->errors(['email' => 'Invalid email']);

        return new RedirectResponse('/test/validation-errors-target', 302);
    }

    public function validationErrorsNamedBag(): Response
    {
        $this->inertia->errors(['name' => 'Required'], 'login');

        return $this->inertia->render('TestComponent', ['foo' => 'bar']);
    }

    public function validationErrorsOverride(): Response
    {
        $this->inertia->errors(['auto' => 'injected']);

        return $this->inertia->render('TestComponent', ['errors' => ['explicit' => true]]);
    }

    public function validationErrorsMixedBags(): Response
    {
        $this->inertia->errors(['email' => 'Invalid email']);
        $this->inertia->errors(['name' => 'Required'], 'login');

        return $this->inertia->render('TestComponent', ['foo' => 'bar']);
    }

    public function flashBagErrorsRedirect(Request $request): Response
    {
        $session = $request->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            throw new \LogicException('The test kernel session must support flash messages.');
        }

        $session->getFlashBag()->add('errors', ['email' => 'Invalid email']);

        return new RedirectResponse('/test/validation-errors-target', 302);
    }

    public function statelessPage(): Response
    {
        $this->inertia->flash('status', 'saved');
        $this->inertia->clearHistory();
        $this->inertia->errors(['email' => 'Invalid email']);

        return $this->inertia->render('TestComponent', ['foo' => 'bar']);
    }

    public function mergeAtPath(): Response
    {
        return $this->inertia->render('TestComponent', [
            'posts' => new MergeProp(static fn () => ['data' => [1, 2], 'meta' => ['total' => 2]], appendsAtPaths: 'data'),
        ]);
    }

    public function prependAtPath(): Response
    {
        return $this->inertia->render('TestComponent', [
            'posts' => new MergeProp(static fn () => ['items' => [1, 2]], prependsAtPaths: 'items'),
        ]);
    }

    public function mergePathMixed(): Response
    {
        return $this->inertia->render('TestComponent', [
            'posts' => new MergeProp(static fn () => [1, 2]),
            'comments' => new MergeProp(static fn () => ['data' => [1, 2]], appendsAtPaths: 'data'),
        ]);
    }

    public function deferAtPath(): Response
    {
        return $this->inertia->render('TestComponent', [
            'eager' => 'eager-value',
            'deferred' => (new DeferProp(static fn () => ['data' => [1, 2]]))->appendAt('data'),
        ]);
    }

    public function deferOnce(): Response
    {
        return $this->inertia->render('TestComponent', [
            'eager' => 'eager-value',
            'deferred' => (new DeferProp(static fn () => 'deferred-once-value'))->once(),
        ]);
    }

    public function optionalOnce(): Response
    {
        return $this->inertia->render('TestComponent', [
            'eager' => 'eager-value',
            'permissions' => $this->inertia->optional(static fn () => ['read', 'write'])->once(),
        ]);
    }

    public function sharedPropsEmit(): Response
    {
        $this->inertia->share('auth', ['user' => 'Alice']);
        $this->inertia->share('appName', 'MyApp');

        return $this->inertia->render('TestComponent', ['local' => 'value']);
    }

    public function sharedPropsDotKeys(): Response
    {
        $this->inertia->share('auth.user', static fn () => ['name' => 'Alice']);
        $this->inertia->share('auth.permissions', ['edit-posts']);
        $this->inertia->share('appName', 'MyApp');

        return $this->inertia->render('TestComponent', ['auth.user.role' => 'admin']);
    }

    public function sharedPropsEmpty(): Response
    {
        return $this->inertia->render('TestComponent', ['local' => 'value']);
    }

    public function mapRequestPayloadForm(): Response
    {
        return $this->inertia->render('TestComponent', ['foo' => 'bar']);
    }

    public function mapRequestPayloadSubmit(#[MapRequestPayload] CreateUserPayload $payload): Response
    {
        return new RedirectResponse('/test/validation-errors-target', 303);
    }

    public function mapQueryStringSearch(#[MapQueryString] SearchQuery $query): Response
    {
        return $this->inertia->render('TestComponent', ['term' => $query->term]);
    }

    public function throwValidationFailed(): Response
    {
        $violations = new ConstraintViolationList([
            new ConstraintViolation('Manual message.', null, [], null, 'name', null),
        ]);

        throw new ValidationFailedException(null, $violations);
    }

    public function throwNestedValidationFailed(): Response
    {
        $violations = new ConstraintViolationList([
            new ConstraintViolation('Item name required.', null, [], null, 'items[0].name', null),
            new ConstraintViolation('Tag required.', null, [], null, '[tags][1]', null),
        ]);

        throw new ValidationFailedException(null, $violations);
    }

    public function throwNotFound(): Response
    {
        throw new NotFoundHttpException('Page not found');
    }

    public function throwServerError(): Response
    {
        throw new \RuntimeException('Internal server error');
    }

    public function nestedProps(): Response
    {
        $this->inertia->share('auth', [
            'user' => static fn () => ['name' => 'Tony'],
            'permissions' => ['read'],
        ]);

        return $this->inertia->render('TestComponent', [
            'stats' => ['count' => static fn () => 42, 'label' => 'visits'],
            'feed' => [
                'posts' => new MergeProp(static fn () => [['id' => 1]], matchOn: 'id'),
                'comments' => new DeferProp(static fn () => ['c1']),
            ],
            'other' => 'o',
        ]);
    }

    public function closureOnPartialReload(): Response
    {
        return $this->inertia->render('TestComponent', [
            'cheap' => 'x',
            'expensive' => static fn () => throw new \LogicException('should not run'),
            'reviews' => new DeferProp(static fn () => ['a']),
        ]);
    }
}
