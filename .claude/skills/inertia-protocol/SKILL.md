---
name: inertia-protocol
description: Complete reference for the Inertia.js v3 server-side protocol. Auto-loaded when working on InertiaResponse, InertiaListener, Inertia service, or any HTTP response handling.
---

# Inertia.js v3 Protocol Reference

## Request Detection

```php
// Detect an Inertia XHR request
$isInertia = $request->headers->has('X-Inertia')
    && 'true' === $request->headers->get('X-Inertia');

// Asset versioning
$clientVersion = $request->headers->get('X-Inertia-Version');

// Partial reload detection
$isPartial    = $request->headers->has('X-Inertia-Partial-Component');
$partialComp  = $request->headers->get('X-Inertia-Partial-Component'); // component name
$onlyProps    = $request->headers->get('X-Inertia-Partial-Data');       // CSV: include list
$exceptProps  = $request->headers->get('X-Inertia-Partial-Except');     // CSV: exclude list
$resetProps   = $request->headers->get('X-Inertia-Reset');              // CSV: keys omitted from merge metadata → client replaces instead of merging
$onceProps    = $request->headers->get('X-Inertia-Except-Once-Props');  // CSV: already-loaded once keys
$scrollIntent = $request->headers->get('X-Inertia-Infinite-Scroll-Merge-Intent'); // 'append'|'prepend'

// Other headers sent by client (no server action required unless noted):
// X-Requested-With: XMLHttpRequest
// Purpose: prefetch               (prefetch request — server may optimize, but not required)
// Cache-Control: no-cache         (reload requests)
// X-Inertia-Error-Bag: {bag}      (validation error bag name override)
// Precognition: true              (Precognition validation — out of scope for this bundle)
```

## Response Types

### HTML (first visit — no X-Inertia header)

```php
// v3: JSON embedded in a <script> tag (prevents </script> injection without extra escaping)
$json = json_encode($pageObject, JSON_HEX_TAG | JSON_THROW_ON_ERROR);
$html = '<script data-page="app" type="application/json">' . $json . '</script>' . "\n" . '<div id="app"></div>';
// Headers: Vary: X-Inertia
// Note: JSON_HEX_TAG alone is sufficient inside a <script> tag — JSON_HEX_APOS/AMP/QUOT are NOT needed.
```

### JSON (XHR visit — X-Inertia: true header present)

```php
$response = new JsonResponse($pageObject);
$response->headers->set('X-Inertia', 'true');
$response->headers->set('Vary', 'X-Inertia');
$response->headers->set('Content-Type', 'application/json');
```

## Page Object (v3) — Canonical Structure

```php
$pageObject = [
    // ALWAYS present:
    'component'      => 'User/Edit',          // string
    'props'          => ['errors' => new \stdClass(), ...], // always has 'errors' key — empty must encode as {} not []
    'url'            => '/user/123',           // RELATIVE path + query (no scheme/host)
    'version'        => '6b16b94d7c51',        // string|null

    // CONDITIONAL — omit entirely when false/empty:
    // v3 history flags: only present when true (omit when false — v3 change from v2)
    'clearHistory'   => true,
    'encryptHistory' => true,
    'preserveFragment' => true,              // NEW v3: preserve URL hash on redirect; omit when false

    // Flash data: TOP-LEVEL field (NOT inside props) — v3 change from v2
    // Omit when empty; client defaults to {} when absent
    'flash'          => ['message' => 'Saved!'],

    // Shared prop keys metadata (NEW v3): list of keys that come from Inertia::share()
    // Allows client to distinguish shared vs page-specific props. Omit when empty.
    'sharedProps'    => ['auth', 'appName'],

    // Other conditional fields (omit when empty):
    'deferredProps'  => ['default' => ['comments'], 'sidebar' => ['related']],
    'mergeProps'     => ['posts'],             // or ['posts.data'] for sub-path
    'prependProps'   => ['notifications'],
    'deepMergeProps' => ['conversations'],
    'matchPropsOn'   => ['posts.id', 'notifications.id'],
    'onceProps'      => ['plans' => ['prop' => 'plans', 'expiresAt' => null]],
    'scrollProps'    => ['posts' => ['pageName' => 'page', 'nextPage' => 2,
                                     'previousPage' => null, 'currentPage' => 1]],
];
```

### Official page object examples (v3):

**Basic (clearHistory/encryptHistory absent when false):**
```json
{ "component": "User/Edit", "props": { "errors": {}, "user": { "name": "Jonathan" } },
  "url": "/user/123", "version": "6b16b94d7c51cbe5b1fa42aac98241d5" }
```

**With clearHistory:**
```json
{ "component": "User/Edit", "props": { "errors": {} }, "url": "/users", "version": null,
  "clearHistory": true }
```

**With flash data (top-level, not in props):**
```json
{ "component": "User/Edit", "props": { "errors": {} }, "url": "/users", "version": null,
  "flash": { "message": "User created!" } }
```

**With sharedProps:**
```json
{ "component": "Dashboard", "props": { "errors": {}, "auth": {...}, "appName": "MyApp" },
  "url": "/dashboard", "version": null, "sharedProps": ["auth", "appName"] }
```

**With deferred props:**
```json
{ "deferredProps": { "default": ["comments", "analytics"], "sidebar": ["relatedPosts"] } }
```

**With merge props:**
```json
{ "mergeProps": ["posts"], "prependProps": ["notifications"],
  "deepMergeProps": ["conversations"],
  "matchPropsOn": ["posts.id", "notifications.id", "conversations.data.id"] }
```

**With scroll props:**
```json
{ "mergeProps": ["posts.data"],
  "scrollProps": { "posts": { "pageName": "page", "previousPage": null, "nextPage": 2, "currentPage": 1 } } }
```

**With once props:**
```json
{ "onceProps": { "plans": { "prop": "plans", "expiresAt": null } } }
```

## Asset Versioning (409)

```php
// In kernel.request listener:
// IMPORTANT: version check must run BEFORE consuming any FlashBag keys (e.g. 'errors'),
// so that all flash data including 'errors' is still present when reflashing for the 409.
if (
    $request->isMethod('GET')
    && $isInertia
    && null !== $serverVersion
    && $clientVersion !== $serverVersion
) {
    // Reflash ALL flash data (consume + re-add) so nothing is lost across the hard reload.
    $session = $request->getSession();
    $flashBag = $session->getFlashBag();
    $flashes = $flashBag->all();  // all() consumes; peekAll() would cause duplication
    foreach ($flashes as $type => $messages) {
        foreach ($messages as $message) {
            $flashBag->add($type, $message);
        }
    }

    return new Response('', 409, [
        'X-Inertia-Location' => $request->getUri(), // absolute URL — browser navigates here
    ]);
}
// Only consume 'errors' flash AFTER the 409 path has been handled.
```

## 302 → 303 Redirect Conversion

Only on PUT, PATCH, DELETE — **not** POST. Spec: "When redirecting after a PUT, PATCH, or DELETE request, you must use a 303 response code."

```php
// In kernel.response listener:
if (302 === $response->getStatusCode()
    && \in_array($request->getMethod(), ['PUT', 'PATCH', 'DELETE'], true)) {
    $response->setStatusCode(303);
}
```

## Partial Reload Filtering Rules

```
isPartial = (X-Inertia-Partial-Data OR X-Inertia-Partial-Except) AND component matches

On full render (not partial):
  - errors: always resolved
  - OptionalProp / optional(): SKIP
  - DeferProp: SKIP (goes to deferredProps metadata)
  - OnceProp: resolve UNLESS key in X-Inertia-Except-Once-Props
  - AlwaysProp: always resolve
  - MergeProp, ScrollProp, Closure, plain value: resolve

On partial reload:
  - errors: always included (ignores $only/$except)
  - AlwaysProp: always included (ignores $only/$except)
  - $except wins over $only when both present
  - OptionalProp: only if key explicitly in $only
  - DeferProp: only if key explicitly in $only (deferred fetch)
  - OnceProp: X-Inertia-Except-Once-Props is IGNORED on partials (only full XHR visits)
  - OnceProp: follows normal $only/$except filtering
  - MergeProp, ScrollProp: follows $only/$except filtering
```

## 409 Responses — Two Distinct Types (v3)

v3 distinguishes two kinds of 409 responses by header:

| Header | Client behaviour | Use case |
|--------|-----------------|----------|
| `X-Inertia-Location: <absolute-url>` | Hard browser redirect (`window.location.href`) | External URL, `Inertia::location()` |
| `X-Inertia-Redirect: <absolute-url>` | Soft SPA navigation (`router.visit()`) | Redirect with URL fragment (hash) |

**NEW v3 — `X-Inertia-Redirect`** (in Laravel Middleware `onRedirectWithFragment()`):
```php
// When a redirect contains a URL fragment (#hash):
return response('', 409, ['X-Inertia-Redirect' => $response->headers->get('Location')]);
```
The client performs a soft navigation, preserving the fragment correctly.

`X-Inertia-Location` (existing, hard redirect):
```php
return response('', 409, ['X-Inertia-Location' => $request->getUri()]);
```

## HTTP Status Codes

| Code | When |
|------|------|
| 200  | Normal response (HTML or JSON) |
| 302  | Redirect (GET requests) |
| 303  | Redirect after PUT/PATCH/DELETE |
| 409  | Version mismatch OR external redirect (`X-Inertia-Location`) OR fragment redirect (`X-Inertia-Redirect`) |

## Props Types Summary

| Type | PHP API | Standard visit | Partial reload | Page object key |
|------|---------|----------------|----------------|-----------------|
| Direct value | `mixed` | ✅ Always | ✅ Optionally | `props` |
| Closure | `fn() => ...` | ✅ Always | ✅ Optionally | `props` |
| OptionalProp | `optional()` | ❌ Never | ✅ Only if in `$only` | `props` |
| AlwaysProp | `always()` | ✅ Always | ✅ Always | `props` |
| DeferProp | `defer($cb, $group)` | ❌ Never | ✅ Separate XHR | `deferredProps` |
| OnceProp | `once()` | ✅ First time | ❌ Skip if in `X-Inertia-Except-Once-Props` | `props` + `onceProps` |
| MergeProp | `merge()` / `deepMerge()` *(our bundle: `merge(deep:true)`)* | ✅ Yes | ✅ Yes | `props` + `mergeProps`/`prependProps`/`deepMergeProps` |
| ScrollProp | `scroll()` | ✅ Yes | ✅ Yes | `props` + `mergeProps` + `scrollProps` |

**Note on our bundle API:**
- `optional()` is the only name; `lazy()` was removed in v3 (calling it is a fatal error)
- `Inertia::deepMerge()` → our bundle: `$inertia->merge($cb, deep: true)` (no separate factory)
- `Inertia::defer()->once()` → not yet supported in our bundle
