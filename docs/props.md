# Prop types

Beyond plain values and closures, the bundle provides several special prop types for controlling when and how data is sent to the client.

## Summary

| Type | Method | Full render | Partial reload | Notes |
|------|--------|-------------|----------------|-------|
| Plain value / Closure | — | ✅ Always | ✅ Optionally | Standard prop |
| `LazyProp` | `lazy()` / `optional()` | ❌ Never | ✅ Only if requested | Must be in `X-Inertia-Partial-Data` |
| `AlwaysProp` | `always()` | ✅ Always | ✅ Always | Bypasses partial filters |
| `DeferProp` | `defer()` | ❌ Never | ✅ Separate XHR | Client fetches after page load |
| `OnceProp` | `once()` | ✅ First time | ✅ Unless cached | Client caches; supports modifiers |
| `MergeProp` | `merge()` / `deepMerge()` | ✅ Always | ✅ Always | Merged into existing client value |
| `ScrollProp` | `scroll()` | ✅ Always | ✅ Always | Merge + pagination metadata |

## LazyProp — optional props

Never sent on full renders. Only resolved when the key is explicitly listed in `X-Inertia-Partial-Data`. Use for expensive data only needed on partial reloads (e.g. a detail panel).

`optional()` is an alias for `lazy()` matching the official Inertia v3 API name:

```php
return $inertia->render('Reports/Show', [
    'report'   => $report,
    'comments' => $inertia->lazy(fn () => $this->commentRepo->findBy(['report' => $report])),
    // identical:
    'comments' => $inertia->optional(fn () => $this->commentRepo->findBy(['report' => $report])),
]);
```

### Chaining with once()

`optional()->once()` combines lazy loading with client-side caching: the prop is absent on full renders, resolved only when explicitly requested, and cached by the client after the first resolution.

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

`defer()->once()` fetches the prop via deferred XHR on the first visit, then caches the result. Subsequent navigations to the same page skip the XHR entirely.

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
    ->as('alias')            // Send under a different key name
    ->until(3600)            // Expire after N seconds (also accepts \DateTimeInterface)
    ->fresh();               // Always send fresh, bypassing client cache
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
