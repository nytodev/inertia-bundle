# Testing

The bundle ships with `AssertableInertiaPage`, a fluent assertion helper for testing Inertia responses, and the `InertiaAssertions` trait that adds `assertInertia()` to your test cases.

## Setup

Use the `InertiaAssertions` trait in your functional tests:

```php
use Nytodev\InertiaBundle\Testing\InertiaAssertions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class UserControllerTest extends WebTestCase
{
    use InertiaAssertions;

    // ...
}
```

The trait has no requirement on the base class: it works in a `WebTestCase`, a `KernelTestCase` or a plain PHPUnit `TestCase`.

## assertInertia()

Pass a callback that receives an `AssertableInertiaPage` instance:

```php
public function testUsersIndexRendersCorrectly(): void
{
    $client = static::createClient();
    $client->request('GET', '/users', [], [], ['HTTP_X_INERTIA' => 'true']);

    self::assertInertia($client->getResponse(), function ($page) {
        $page->component('Users/Index')
             ->has('users')
             ->count('users', 3)
             ->where('users.0.name', 'Alice');
    });
}
```

The callback is optional: `assertInertia()` returns the `AssertableInertiaPage`, so assertions can also be chained directly:

```php
self::assertInertia($client->getResponse())
    ->component('Users/Index')
    ->has('users');
```

`assertInertia()` works with both XHR responses (`X-Inertia: true`) and plain HTML first-visit responses (it extracts the page object from the `<script type="application/json">` tag in that case).

## Available assertions

| Method | Description |
|--------|-------------|
| `component(string $name, ?bool $shouldExist = null)` | Assert the rendered component name, optionally that its file exists |
| `url(string $url)` | Assert the page URL |
| `version(string $version)` | Assert the asset version |
| `has(string $key)` | Assert a prop key exists (dot notation supported) |
| `missing(string $key)` | Assert a prop key does not exist |
| `where(string $key, mixed $expected)` | Assert a prop value — pass a Closure for custom assertions |
| `whereAll(array $props)` | Assert multiple prop values at once |
| `count(string $key, int $expected)` | Assert the count of a prop array |
| `dump()` | Dump the page object and continue chaining |
| `dd()` | Dump the page object and stop execution |

All assertion methods return `$this` for chaining.

## Dot notation

`has()`, `missing()`, `where()`, and `count()` support dot notation to traverse nested props:

```php
$page->has('user.address.city')
     ->where('user.address.city', 'Paris')
     ->missing('user.password');
```

## Closure assertions

Pass a Closure to `where()` for custom logic. The assertion fails when the closure returns `false`:

```php
$page->where('users', function (array $users): bool {
    return count($users) > 0 && $users[0]['active'] === true;
});
```

A closure that returns nothing is also accepted, so it can make its own assertions:

```php
$page->where('users', function (array $users): void {
    self::assertNotEmpty($users);
});
```

## Component file check

`component()` can also assert that the page component file exists on disk. Configure the directories to scan once, for example in `setUp()`:

```php
use Nytodev\InertiaBundle\Testing\AssertableInertiaPage;

protected function setUp(): void
{
    AssertableInertiaPage::configure(
        paths: [__DIR__.'/../assets/pages'],
        ensureExists: true,
    );
}
```

`extensions` defaults to `vue`, `jsx`, `tsx`, `js`, `ts` and `svelte`. Pass `$shouldExist` to `component()` to force (`true`) or skip (`false`) the check for one assertion.

The `inertia.testing.ensure_pages_exist` bundle option is deprecated since 3.2 and has no effect: use `AssertableInertiaPage::configure()` instead.

## Testing without the trait

Use `AssertableInertiaPage::fromResponse()` directly:

```php
use Nytodev\InertiaBundle\Testing\AssertableInertiaPage;

$page = AssertableInertiaPage::fromResponse($response);
$page->component('Users/Index')->has('users');
```
