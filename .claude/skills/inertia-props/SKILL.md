---
name: inertia-props
description: Complete PHP API reference for all Inertia.js v3 prop types (optional/lazy, always, defer, once, merge, deepMerge, scroll). Auto-loaded when working on Props/, Service/Inertia.php, or InertiaResponse.php.
---

# Inertia.js v3 — Prop Types Reference

Complete reference for all prop types: official PHP API, our bundle's API, behavior, and page object output.

---

## Closures (lazy evaluation)

```php
// Closure: evaluated only when included (not when filtered by partial reload)
'users' => fn () => User::findAll(),
```
- Full render: ✅ resolved
- Partial (`only`/`except`): ✅ optionally
- Page object: `props.users`

---

## `optional()` — OptionalProp

**Official v3 API:**
```php
Inertia::optional(fn () => Permission::all())
// Chain with once:
Inertia::optional(fn () => Permission::all())->once()
```
**Our bundle:** `$inertia->optional(fn () => ...)` — canonical name. `lazy()` was removed in v3 — calling it is a fatal error.

- Full render: ❌ **never included**
- Partial with `only: ['permissions']`: ✅ resolved
- Partial without explicit `only`: ❌ skipped
- Page object: `props` (when included)

---

## `always()` — AlwaysProp

```php
Inertia::always(fn () => auth()->user())
```
Our bundle: `$inertia->always(fn () => ...)`

- Full render: ✅ always
- Partial (`only`/`except`): ✅ **always — bypasses all filters**
- Page object: `props`

---

## `defer()` — DeferProp

**Official v2 API:**
```php
// Single prop, default group
Inertia::defer(fn () => Permission::all())

// Named group (loads in parallel with other same-group props)
Inertia::defer(fn () => Team::all(), 'attributes')
Inertia::defer(fn () => Project::all(), 'attributes')

// Chain with once (our bundle: NOT YET IMPLEMENTED)
Inertia::defer(fn () => Stats::generate())->once()

// Chain with merge
Inertia::defer(fn () => User::paginate())->merge()
Inertia::defer(fn () => $data)->deepMerge()
Inertia::defer(fn () => $items)->prepend()
Inertia::defer(fn () => $items)->matchOn('id')
Inertia::defer(fn () => $items)->appendAt('data')
Inertia::defer(fn () => $items)->prependAt('data')
```

Our bundle: `$inertia->defer(fn () => ..., 'groupName')` — `->once()` not yet supported.

- Full render: ❌ **never in `props`** — goes to `deferredProps` metadata only
- Deferred XHR (`X-Inertia-Partial-Data: propKey`): ✅ resolved
- Page object (full render): `deferredProps: { "default": ["propKey"] }`
- Page object (deferred XHR): `props.propKey` + merge metadata if applicable

**DeferProp fluent merge methods (our bundle):**
```php
$inertia->defer(fn () => $items)
    ->merge()          // enables mergeProps
    ->deepMerge()      // enables deepMergeProps (also sets merge)
    ->prepend()        // enables prependProps (also sets merge)
    ->matchOn('id')    // adds to matchPropsOn as "propKey.id"
    ->appendAt('data') // adds "propKey.data" to mergeProps
    ->prependAt('data')// adds "propKey.data" to prependProps
```

---

## `once()` — OnceProp

**Official v2 API:**
```php
// Basic
Inertia::once(fn () => Plan::all())

// Modifiers (chainable)
Inertia::once(fn () => ExchangeRate::all())->until(now()->addDay())  // expiry
Inertia::once(fn () => Plan::all())->until(3600)                    // seconds from now
Inertia::once(fn () => Role::all())->as('roles')                    // alias key
Inertia::once(fn () => Plan::all())->fresh()                        // force re-resolve
Inertia::once(fn () => Plan::all())->fresh($condition)              // conditional

// Global sharing
Inertia::shareOnce('countries', fn () => Country::all())
Inertia::shareOnce('countries', fn () => Country::all())->until(now()->addDay())
```

Our bundle: `$inertia->once(fn () => ...)` — all modifiers implemented.

- Full render: ✅ resolved + metadata in `onceProps`
- Subsequent XHR (client sends `X-Inertia-Except-Once-Props: plans`): ❌ value skipped, **metadata always emitted**
- Partial reload: `X-Inertia-Except-Once-Props` is **ignored** — follows normal `$only`/`$except`
- Page object: `props.plans` (when included) + `onceProps.plans: { prop: "plans", expiresAt: null }`

**OnceProp metadata always emitted** even when value is absent (fix 2026-04-02):
Client needs `onceProps[key]` to know it should reinject from cache.

**`expiresAt`:** Unix timestamp in **milliseconds** (`getTimestamp() * 1000`) or null — the client compares it with `Date.now()`; a seconds value is always "expired". Client auto-invalidates when expired.
**`fresh: true`:** included in metadata when `->fresh()` is set.
**Alias:** `onceProps` key = alias, `prop` field = original key.

---

## `merge()` / `deepMerge()` — MergeProp

**Official v2 API:**
```php
// Append at root (default)
Inertia::merge($items)

// Prepend at root
Inertia::merge($items)->prepend()

// Append at sub-path
Inertia::merge(User::paginate())->append('data')
Inertia::merge($data)->append(['notifications', 'activities'])
Inertia::merge($data)->append('data', matchOn: 'id')
Inertia::merge($data)->append(['users.data' => 'id', 'messages' => 'uuid'])

// Deep merge
Inertia::deepMerge($data)->matchOn('messages.id')

// Combine
Inertia::merge($data)->once()
Inertia::defer(fn() => $data)->deepMerge()
```

**Our bundle:**
```php
// Constructor params (equivalent, different style)
$inertia->merge(fn() => $items)
$inertia->merge(fn() => $items, prepend: true)
$inertia->merge(fn() => $data, deep: true)
$inertia->merge(fn() => $data, matchOn: 'id')
$inertia->merge(fn() => $data, appendsAtPaths: 'data')
$inertia->merge(fn() => $data, prependsAtPaths: 'data')
// Missing: $inertia->deepMerge() factory
```

- Full render: ✅ resolved
- Partial: follows `$only`/`$except`
- **Only merges during partial reloads** — full page visits always replace
- Reset via client: `router.reload({ reset: ['posts'] })` → server receives `X-Inertia-Reset: posts`

**Page object fields:**
```json
{ "mergeProps": ["posts"],        // root append, or "posts.data" for sub-path
  "prependProps": ["notifications"],
  "deepMergeProps": ["conversations"],
  "matchPropsOn": ["posts.id", "notifications.id"] }
```

---

## `scroll()` — ScrollProp

**Official v2 API (Laravel):**
```php
// Auto-detects pagination metadata from Laravel paginators
Inertia::scroll(User::paginate(20))
Inertia::scroll(User::simplePaginate(20))
Inertia::scroll(User::cursorPaginate(20))
Inertia::scroll(UserResource::collection(User::paginate(20)))

// Custom wrapper key (defaults to 'data')
Inertia::scroll($data, wrapper: 'items')

// Custom metadata
Inertia::scroll($data, metadata: new CustomScrollMetadata())

// Multiple paginators (custom pageName avoids query param conflicts)
'users' => Inertia::scroll(fn() => User::paginate(pageName: 'users')),
'orders' => Inertia::scroll(fn() => Order::paginate(pageName: 'orders')),
```

**Our bundle (Symfony — no auto-detection, explicit pagination values):**
```php
$inertia->scroll(
    fn () => $posts,        // callback returns plain array (not paginator object)
    pageName: 'page',       // query param name (default: 'page')
    nextPage: $nextPage,    // int|string|null
    previousPage: $prevPage,
    currentPage: $page,
    prepend: false,
)

// Optional: defer
$inertia->scroll(fn () => $posts, nextPage: 2)->defer()
$inertia->scroll(fn () => $posts, nextPage: 2)->defer('myGroup')
```

**Design difference vs Laravel:**
- Laravel emits `mergeProps: ["posts.data"]` (sub-path within paginator)
- Our bundle emits `mergeProps: ["posts"]` (root merge)
- Our callback should return a **plain array**, not a paginator object

**Page object:**
```json
{ "mergeProps": ["posts"],
  "scrollProps": { "posts": { "pageName": "page", "previousPage": null, "nextPage": 2, "currentPage": 1 } } }
```

**Client sends `X-Inertia-Infinite-Scroll-Merge-Intent: prepend|append`** to override the static `prepend` flag.
**Client sends `X-Inertia-Reset: posts`** to replace instead of merging: the key is omitted from `mergeProps`/`prependProps`/`deepMergeProps`/`matchPropsOn` (`collectMergeArrays`) and `scrollProps.posts.reset` is `true` (`collectScrollProps`).

---

## `flash()` — Flash Data

**Official v3 API (Laravel):**
```php
Inertia::flash('message', 'User created!');
return back();

// Chainable
return Inertia::flash('newUserId', $user->id)->back();
return Inertia::render('Page', $props)->flash('highlight', $id);
```

**Our bundle:** `$inertia->flash(string $key, mixed $value): void` (returns void, not chainable).

**v3 IMPORTANT: Flash is a TOP-LEVEL page object field, NOT inside `props`.**
- v2: `props.flash.key` → v3: `page.flash.key` (top-level)
- Omitted when empty; client defaults to `{}`
- Client fires `inertia:flash` event when `flash` contains data
- Not stored in browser history state (client clears `flash: {}` before pushState)

**Current bundle status:** Our bundle currently puts flash inside `props.flash` — this needs to be moved to a top-level `flash` field in `buildPageObject()`. Tracked as an open gap.

---

## `errors` Prop — Invariant

```
errors is ALWAYS present in props, default: []
Even if a DeferProp or OptionalProp was passed as 'errors', the key always exists.
AlwaysProp wrapping errors in Laravel middleware — our bundle guarantees it in InertiaResponse::build().
```

---

## Server-Side Prop Sharing

```php
// Share across all pages
$inertia->share('appName', 'MyApp');
$inertia->share('auth.user', fn () => $user?->toArray());

// Share once (cached client-side)
$inertia->shareOnce('countries', fn () => $this->countryRepo->findAll());
// wraps plain values/closures in OnceProp automatically

// Per-request: in a controller
return $inertia->render('Users/Index', [
    'users' => fn () => $this->userRepo->findAll(),
]);
```
