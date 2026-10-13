# Prop types

Beyond plain values and closures, the bundle provides several special prop types for controlling when and how data is sent to the client.

## Summary

| Type | Method | Full render | Partial reload | Notes |
|------|--------|-------------|----------------|-------|
| Plain value / Closure | — | ✅ Always | ✅ Optionally | Standard prop |
| `OptionalProp` | `optional()` | ❌ Never | ✅ If requested | Named in `only`, or not excluded by an `except`-only reload |
| `AlwaysProp` | `always()` | ✅ Always | ✅ Always | Bypasses partial filters |
| `DeferProp` | `defer()` | ❌ Never | ✅ Separate XHR | Client fetches after page load |
| `OnceProp` | `once()` | ✅ First time | ✅ Unless cached | Client caches; supports modifiers |
| `MergeProp` | `merge()` / `deepMerge()` | ✅ Always | ✅ Always | Merged into existing client value |
| `ScrollProp` | `scroll()` | ✅ Always | ✅ Always | Merge + pagination metadata |

## Closures

A closure prop is called only when the response needs its value. It is called **without arguments**, as are the closures given to `optional()`, `defer()`, `once()`, `merge()` and the other prop types. Unlike inertia-laravel, the bundle injects no services into them. Capture what the closure needs from the controller instead:

```php
public function index(Inertia $inertia, UserRepository $users): Response
{
    return $inertia->render('Users/Index', [
        'users' => fn () => $users->findAll(),
        'stats' => $inertia->optional(fn () => $users->countByRole()),
    ]);
}
```

## OptionalProp — optional props

Never sent on full renders. Resolved on a partial reload that requests it: `router.reload({ only: ['comments'] })`, or an `except`-only reload that does not exclude it (same as inertia-laravel). Use for expensive data only needed on demand (e.g. a detail panel).

```php
return $inertia->render('Reports/Show', [
    'report'   => $report,
    'comments' => $inertia->optional(fn () => $this->commentRepo->findBy(['report' => $report])),
]);
```

> `lazy()` was removed in v3 — use `optional()`.

### Chaining with once()

`optional()->once()` combines lazy loading with client-side caching: the prop is absent on full renders, resolved when requested, and cached by the client after the first resolution. Requesting it explicitly (`only`) always refreshes it.

```php
return $inertia->render('Dashboard', [
    'permissions' => $inertia->optional(fn () => $this->getPermissions())->once(),
]);
```

> **Scope:** the once-cache is tied to `page.props`. If you navigate to a page that does not have this prop and return, the cache is lost. This is Inertia.js v3 client behaviour, not a server-side limitation.

## AlwaysProp — always included

Included in every response, even when a partial reload filters other props. Use for data that must always be up-to-date (e.g. notification count).

```php
return $inertia->render('Dashboard', [
    'stats'         => $inertia->always(fn () => $this->buildStats()),
    'recentEvents'  => $this->getRecentEvents(),
]);
```

## DeferProp — deferred loading

Excluded from the initial page render. The client makes a separate XHR after the page loads to fetch deferred props. Multiple deferred props with the same group name are fetched in a single request.

```php
return $inertia->render('Users/Index', [
    'users'    => $this->userRepo->findAll(),
    'stats'    => $inertia->defer(fn () => $this->buildStats()),            // group: 'default'
    'comments' => $inertia->defer(fn () => $this->getComments(), 'sidebar'), // group: 'sidebar'
]);
```

A deferred prop can also be mergeable (infinite scroll within a deferred section):

```php
$inertia->defer(fn () => $this->paginate($page))->merge()->matchOn('id')
```

### Chaining with once()

`defer()->once()` fetches the prop via deferred XHR on the first visit, then caches the result. Subsequent visits skip the XHR entirely: the prop is no longer listed in `deferredProps` while the client has it. Requesting it explicitly (`only`) always refreshes it.

```php
return $inertia->render('Dashboard', [
    'stats' => $inertia->defer(fn () => $this->buildHeavyStats())->once(),
]);
```

> **Scope:** same as `optional()->once()` — cache is lost when navigating to a page without the prop.

## OnceProp — sent once, cached by client

Sent on the first render and cached by the client. Subsequent visits skip the prop unless the client explicitly invalidates the cache. Ideal for static reference data (countries, permissions…).

```php
return $inertia->render('Orders/Create', [
    'countries' => $inertia->once(fn () => $this->countryRepo->findAll()),
]);
```

**Fluent modifiers:**

```php
$inertia->once(fn () => $data)
    ->as('pricing')          // Client cache key, shared by every page using it (the prop keeps its name)
    ->until(3600)            // Expire after N seconds (also accepts \DateTimeInterface)
    ->fresh();               // Always resolved, even when the client already has it
```

## MergeProp — client-side merge

The resolved value is merged into the existing client-side value instead of replacing it. Essential for infinite scroll and cursor-based pagination.

```php
return $inertia->render('Feed/Index', [
    'posts' => $inertia->merge(fn () => $this->postRepo->paginate($page)),
]);
```

**Options:**

```php
// Prepend new items instead of appending
$inertia->merge(fn () => $newItems, prepend: true)

// Deep (recursive) merge
$inertia->merge(fn () => $nestedData, deep: true)

// Shorthand for deep merge
$inertia->deepMerge(fn () => $nestedData)

// Client-side deduplication by field name
$inertia->merge(fn () => $posts, matchOn: 'id')
$inertia->deepMerge(fn () => $posts, matchOn: 'id')

// Merge at a sub-path of the prop (e.g. prop.data) instead of the root
$inertia->merge(fn () => $result, appendsAtPaths: 'data')
$inertia->merge(fn () => $result, prependsAtPaths: 'data')
```

**Resetting merge state:** `router.reload({ reset: ['posts'] })` sends `X-Inertia-Reset`; the listed keys are left out of the merge metadata, so the client **replaces** their value instead of merging it (useful when a filter changes, or for "refresh from top" in infinite scroll).

## ScrollProp — merge with pagination metadata

Like `MergeProp` but carries pagination information used by the Inertia client to determine the next page to fetch.

```php
return $inertia->render('Posts/Index', [
    'posts' => $inertia->scroll(
        fn () => $this->postRepo->paginate($page),
        pageName: 'page',
        nextPage: $nextPage,
        previousPage: $previousPage,
        currentPage: $currentPage,
        prepend: false,
    ),
]);
```

A scroll prop can also be deferred:

```php
$inertia->scroll(fn () => $this->paginate($page), nextPage: $next)->defer()
```

## Nested props

Closures and prop types can be nested inside arrays, including shared props. They follow the same rules as at the top level, and their metadata uses dot paths:

```php
$inertia->share('auth', [
    'user' => fn () => $this->security->getUser()?->toArray(),
    'permissions' => $inertia->optional(fn () => $this->permissions->forCurrentUser()),
]);

return $inertia->render('Feed/Index', [
    'feed' => [
        'posts' => $inertia->merge(fn () => $this->postRepo->latest(), matchOn: 'id'), // mergeProps: ["feed.posts"]
        'comments' => $inertia->defer(fn () => $this->commentRepo->latest()),     // deferredProps: {"default": ["feed.comments"]}
    ],
]);
```

Partial reloads accept dot paths: `router.reload({ only: ['auth.user'] })` returns only `auth.user` (the client merges it into the existing `auth` object), and `except: ['auth.permissions']` drops only that branch. Requesting a parent (`only: ['auth']`) includes its nested optional props. Everything inside a value returned by a closure is sent as a whole.

A closure is only called when the request needs it: on a partial reload, closures that are not requested — or are listed in `except` — never run.

A prop type may also return another prop type, e.g. `$inertia->always(fn () => $inertia->merge(...))`: the inner one is applied with its own rules and metadata.

### Dot-notation keys

A top-level key containing dots is nested into the props tree, so several places can contribute to the same object:

```php
$inertia->share('auth.user', fn () => $this->security->getUser()?->toArray());
$inertia->share('auth.permissions', $inertia->optional(fn () => $this->permissions->forCurrentUser()));

return $inertia->render('Users/Edit', ['auth.user.role' => 'admin']); // props.auth.user.role
```

`sharedProps` lists the first segment only (`["auth"]`). The value at the end of the path stays lazy and is filtered by its full path (`only: ['auth.permissions']`). A closure found on an intermediate segment (`auth.user` above) is called so the value can be nested into its result, and an intermediate prop type or scalar is replaced by an array, as in inertia-laravel. Only top-level keys are unpacked: a dotted key inside a nested array is kept as is.
