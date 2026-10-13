CHANGELOG
=========

3.2.0
-----

 * Added recursive props resolution: closures and prop objects (`optional()`, `defer()`, `merge()`, `once()`, `scroll()`, `always()`) nested inside arrays are now resolved instead of being serialized as `{}`; their metadata uses dot paths (e.g. `mergeProps: ["feed.posts"]`, `deferredProps: {"default": ["feed.comments"]}`)
 * Added dot-notation partial reloads: `X-Inertia-Partial-Data` / `X-Inertia-Partial-Except` accept paths such as `auth.user` (`router.reload({ only: ['auth.user'] })`)
 * Fixed plain closure props being evaluated on partial reloads even when not requested or explicitly excluded via `except` (#22)
 * Removed the internal `InertiaResponse::resolveProps()` method — props are now resolved by `Response\PropsResolver`
 * Changed `optional()` and `defer()` props to be resolved on except-only partial reloads (`router.reload({ except: [...] })`) unless excluded, matching inertia-laravel — previously they were silently left stale on the client
 * Changed `optional()->once()` and `defer()->once()` props to always be resolved when explicitly requested (`only`), even if the client already has them — previously they could never be refreshed
 * Fixed `X-Inertia-Except-Once-Props` being matched against the prop path instead of the `once()->as()` alias, so aliased once props were never served from the client cache
 * Fixed `once()->fresh()` having no effect: the prop is now resolved even when the client already has it. The non-standard `fresh: true` entry is no longer emitted in `onceProps`
 * Fixed `defer()->once()` props the client already has being listed in `deferredProps`, which triggered a useless deferred request
 * Fixed docs and README still documenting the removed `lazy()` method (use `optional()`)
 * Fixed the session being read on `_stateless` routes (`UnexpectedSessionUsageException` in debug): flash, errors and history flags now fall back to memory there
 * Fixed FlashBag `errors` (`addFlash('errors', [...])`) only reaching XHR visits: they are now read at render time, so a classic form post redirecting to a first visit shows them. String entries are left in the FlashBag for Twig
 * Fixed `#[MapRequestPayload]` / `ValidationFailedException` error keys for nested fields: `items[0].name` is now sent as `items.0.name`, the dot notation the Inertia client uses
 * Changed error bag resolution to match inertia-laravel: when the `default` bag exists, only it is sent (wrapped under `X-Inertia-Error-Bag` when present) — previously a second named bag switched the whole `errors` prop to a per-bag object the client could not read

3.1.1
-----

 * Fixed `onceProps.*.expiresAt` emitted in seconds instead of milliseconds — the client compares it with `Date.now()`, so every `once()->until()` prop was treated as already expired and re-resolved on each visit
 * Fixed keys listed in `X-Inertia-Reset` still being emitted in `mergeProps` / `prependProps` / `deepMergeProps` / `matchPropsOn` — `router.reload({ reset: [...] })` merged into the client value instead of replacing it (merge, deferred merge and scroll props)
 * Fixed empty `errors` prop serialized as `[]` instead of `{}` (XHR, first visit and Serializer paths)

3.1.0
-----

 * Added `intercept_validation_errors` config option (default `true`) — set to `false` to remove the validation listener from the container and keep Symfony's default 4xx behavior on Inertia requests
 * Added `InertiaValidationListener` — automatically converts `ValidationFailedException` (thrown by `#[MapRequestPayload]` / `#[MapQueryString]` or manually) into the Inertia error flow on `X-Inertia` requests: one message per field (first violation wins), errors stored in session, `303 See Other` redirect back to the `Referer` (same-host URLs and relative paths only — cross-origin or malformed referers fall back to the current URL), errors injected as the `errors` prop on the next render. Requires `symfony/validator` (definition removed at compile time when absent) and a session (interception skipped without one). Non-Inertia requests keep Symfony's default 4xx behavior

3.0.2
-----

 * Fixed page object `url` field to include `X-Forwarded-Prefix` when the app runs behind a reverse proxy (e.g. Traefik with `stripPrefix`)

3.0.1
-----

 * Fixed `normalizePage()` — added `interface_exists()` guard before `instanceof NormalizerInterface` to prevent fatal errors when `symfony/serializer` is not installed
 * Fixed `normalizePage()` — explicit `instanceof` check now throws a descriptive `LogicException` when the injected service does not implement the required interface

3.0.0
-----

 * Added full Inertia.js v3 server-side protocol compliance
 * Changed HTML embedding: `<script data-page="app" type="application/json">` replaces `data-page` attribute on `<div>`
 * Changed `clearHistory` and `encryptHistory`: omitted from page object when `false` (v2 always included them)
 * Changed `flash`: moved to top-level page object key, no longer nested inside `props`
 * Added `sharedProps` top-level field listing keys injected via `share()` (opt-out via `expose_shared_prop_keys: false`)
 * Added `preserveFragment` flag — signals client to preserve URL fragment after redirect
 * Added `X-Inertia-Redirect` header emitted on fragment redirects
 * Added `ExceptionResponse` DTO and `InertiaExceptionListener` for Inertia-aware error handling
 * Added `Inertia::handleExceptionsUsing()` to replace error pages with Inertia components
 * Added `BundleDetector` — auto-detects SSR bundle (`ssr.mjs` / `ssr.js`) without hardcoded path
 * Added `SsrState` — centralises per-request SSR render cache
 * Improved `HttpSsrGateway` — HTTP 4xx/5xx error handling, `throwOnError` support, path exclusion
 * Changed `Inertia::render()` now accepts a `BackedEnum` as component name
 * Added `AssertableInertiaPage::configure()` — define component paths and extensions to scan
 * Added component file existence assertion in `AssertableInertiaPage::component()`
 * Renamed `LazyProp` to `OptionalProp` — `lazy()` alias kept for backwards compatibility
 * Added `expose_shared_prop_keys` configuration option
 * Added optional Symfony Normalizer support — pass a `$serializationContext` array to `Inertia::render()` or `renderInertia()` to normalize PHP objects (e.g. Doctrine entities) in props via `NormalizerInterface`; requires `symfony/serializer`

2.0.3
-----

 * Fixed page object `url` field now includes `X-Forwarded-Prefix` when the app runs behind a reverse proxy (e.g. Traefik with `stripPrefix`)

2.0.2
-----

 * Fixed missing `symfony/twig-bundle` dependency declaration in `composer.json`

2.0.1
-----

 * Added Dependabot for weekly Composer and GitHub Actions updates
 * Added `.gitattributes` to exclude dev files from Packagist archives
 * Added `CONTRIBUTING.md` and `CODE_OF_CONDUCT.md`
 * Updated `SECURITY.md`
 * Added Packagist keywords and homepage to `composer.json`
 * Bumped `phpstan/phpstan` to `^2.1`

2.0.0
-----

 * Added full Inertia.js v2 server-side protocol compliance (page object, XHR detection, asset versioning 409, 302→303 redirect conversion)
 * Added partial reloads via `X-Inertia-Partial-Data`, `X-Inertia-Partial-Except` and `X-Inertia-Reset` headers
 * Added `clearHistory` and `encryptHistory` flags on the page object
 * Added `matchPropsOn` deduplication metadata for merge props
 * Added `Inertia::location()` for external redirects
 * Added `LazyProp` (`lazy()` / `optional()`) — resolved only on explicit partial reload request
 * Added `AlwaysProp` (`always()`) — always included, even in partial reloads
 * Added `DeferProp` (`defer()`) — fetched in a separate XHR by the client
 * Added `OnceProp` (`once()`) — sent once and cached by the client, with `.as()`, `.until()`, `.fresh()` modifiers
 * Added `MergeProp` (`merge()`) — client-side merge with `.prepend()` and `.deepMerge()` variants
 * Added `ScrollProp` (`scroll()`) — infinite-scroll pagination prop with defer support
 * Added `once()` flag on `DeferProp` and `LazyProp`
 * Added `deepMerge()` shorthand on the `Inertia` service
 * Added `Inertia::errors()` for validation errors auto-injection
 * Added `Inertia::clearHistory()` and `encryptHistory()` history control methods
 * Added flash data automatically merged into page props
 * Added server-side rendering (SSR) via external Node.js SSR server
 * Added `inertia:start-ssr`, `inertia:stop-ssr` and `inertia:check-ssr` console commands
 * Added `AssertableInertiaPage` fluent testing DSL
 * Added `ssr_enabled`, `ssr_url` and `encrypt_history` configuration options
