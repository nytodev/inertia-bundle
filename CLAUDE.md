# CLAUDE.md — symfony-inertia-bundle

Symfony Bundle implementing the **Inertia.js v3 server-side protocol** — the equivalent of
`inertiajs/inertia-laravel` for Symfony 6.4 / 7.4 / 8.0.

---

## Project overview

| Key | Value |
|-----|-------|
| Type | Reusable Symfony Bundle (not an app) |
| Inertia.js | v3 protocol (branch `3.x`) |
| Symfony min | 6.4 LTS |
| PHP min | 8.1 |
| Bundle class | `AbstractBundle` — never `Bundle` + separate `Extension` |
| Packagist name | `nytodev/inertia-bundle` |
| DI alias | `inertia` |
| Main service | `Nytodev\InertiaBundle\Service\Inertia` |

---

## Verification commands — run after every change

```bash
vendor/bin/phpunit                                    # full test suite
vendor/bin/phpunit tests/Unit/                        # unit tests only
vendor/bin/phpunit tests/Functional/                  # functional tests only
vendor/bin/phpstan analyse src/ tests/ --level=8      # static analysis
vendor/bin/php-cs-fixer fix --dry-run --diff          # code style check
vendor/bin/php-cs-fixer fix                           # code style fix
```

PHPStan 2.x is required: 1.x cannot parse the property hooks used by Symfony 8.1+ and reports hundreds of
false `unknown class Symfony\...\Request/Response` errors. Expected result: zero errors on `src/ tests/`.

---

## PHP conventions

- `declare(strict_types=1)` in every PHP file
- `final` on every class unless extension is explicitly supported
- `readonly` on every property that doesn't mutate
- Constructor promotion for all DI: `public function __construct(private readonly Foo $foo)`
- Strict comparisons only: `===` and `!==`, never `==`
- Yoda conditions: `if (null === $value)` not `if ($value === null)`
- `match` over `switch` for expressions
- camelCase for variables/methods, PascalCase for classes
- Max 800 lines per file (200–400 is typical)
- No `var_dump`, `dd`, `dump` in production code

---

## Symfony Bundle conventions

### What to do
- Use `AbstractBundle` — `configure()` for schema, `loadExtension()` for services
- Import config schema from `config/definition.php` via `$definition->import('../config/definition.php')`
- Load services via `$container->import('../config/services.yaml')` (physical path, not `@Bundle`)
- Prefix all services with `inertia.*`
- Declare all services `public: false`
- Create public aliases for autowiring: `Nytodev\InertiaBundle\Service\Inertia: { alias: inertia.service, public: true }`
- Name event listeners with suffix `Listener` (not `Subscriber`)
- `$config` in `loadExtension()` is already flat and validated — use it directly

### What NOT to do
- Never create `src/DependencyInjection/InertiaExtension.php`
- Never use `autowire: true` or `autoconfigure: true` in `config/services.yaml`
- Never use logical paths `@InertiaBundle/config/...`
- Never call `processConfiguration()` in `loadExtension()` — AbstractBundle does it already
- Never hardcode config values in PHP classes — always inject via DI

---

## Inertia.js v3 protocol — critical rules

### First visit (no `X-Inertia` header)
Returns full HTML with a `<script>` tag containing the page object JSON:
```html
<script data-page="app" type="application/json">{"component":"...","props":{},...}</script>
<div id="app"></div>
```
JSON must be escaped with `JSON_HEX_TAG | JSON_THROW_ON_ERROR` (prevents `</script>` injection).
`JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT` are not needed inside a `<script>` tag.

### XHR visit (`X-Inertia: true` header present)
Returns JSON page object with headers:
- `Content-Type: application/json`
- `X-Inertia: true`
- `Vary: X-Inertia`

### Page object fields (v3)

**Always present:**
```json
{
    "component": "string",
    "props": { "errors": {} },
    "url": "/path?query",
    "version": "string|null"
}
```
- `url` is a **relative path + query string** (e.g. `/users?page=2`), never an absolute URL with scheme/host
- `clearHistory`, `encryptHistory` and `preserveFragment` are **omitted when false** — only present when `true` (v3 change from v2)
- `errors` empty → JSON **object** `{}`, never `[]` (inertia-laravel: `(object) []`). PHP tests decoding with
  `json_decode(..., true)` cannot tell them apart — assert on the raw JSON string.

**Conditionally present (omitted if empty):**
```json
{
    "deferredProps":  { "default": ["comments"], "sidebar": ["related"] },
    "mergeProps":     ["posts"],
    "prependProps":   ["notifications"],
    "deepMergeProps": ["conversations"],
    "matchPropsOn":   ["posts.id"],
    "onceProps":      { "plans": { "prop": "plans", "expiresAt": 1767225600000 } },
    "scrollProps":    { "posts": { "pageName": "page", "previousPage": null, "nextPage": 2, "currentPage": 1, "reset": false } },
    "sharedProps":    ["auth"],
    "flash":          { "notification": "Saved" }
}
```
- `onceProps.*.expiresAt` is a timestamp in **milliseconds** (the client compares it with `Date.now()`), or `null`

### Asset versioning
- `X-Inertia-Version` header differs from server version → `409 Conflict` + `X-Inertia-Location` header
- Only on `GET` requests
- Reflash session flash data before returning 409

### Redirects
- `302` after `PUT`/`PATCH`/`DELETE` → convert to `303 See Other`
- `302` after `GET` → keep as `302`
- Redirect whose `Location` contains a `#` on an Inertia request (not `Purpose: prefetch`) → `409` +
  `X-Inertia-Redirect: <location>`; the client then visits it and keeps the fragment. Never added to ordinary redirects.

### Partial reloads
- Partial only when `X-Inertia-Partial-Component` equals the rendered component; otherwise both filter headers are ignored
- `X-Inertia-Partial-Data` → include only listed props (CSV)
- `X-Inertia-Partial-Except` → exclude listed props (CSV); if both headers present, `Except` wins
- `X-Inertia-Reset` → listed keys are **omitted from all merge metadata** (`mergeProps`, `prependProps`,
  `deepMergeProps`, `matchPropsOn`) so the client replaces the value instead of merging; `scrollProps.<key>.reset` is `true`.
  The v3 client also appends reset keys to `X-Inertia-Partial-Data`.
- `errors` prop is **always** included regardless of partial reload filters
- `OptionalProp` (`optional()`) closures are **only** resolved when the key is explicitly in `X-Inertia-Partial-Data` — they are NOT resolved in except-only partial reloads or full renders.
  ⚠️ Diverges from inertia-laravel 3.x, which resolves them in except-only partial reloads (audit 2026-10-12, not yet aligned).
- Dot-notation keys (`auth.user`) and nested closures/prop objects inside arrays are **not supported yet** (resolved recursively in inertia-laravel 3.x — known gap)

### Props types and evaluation

| Type | Standard visit | Partial reload | Notes |
|------|---------------|----------------|-------|
| `mixed` (direct value) | ✅ Always | ✅ Optionally | Resolved always |
| `Closure` | ✅ Always | ✅ Optionally | Lazy-evaluated |
| `OptionalProp` (`optional()`) | ❌ Never | ✅ Only if in `$only` | Must be explicitly requested; `lazy()` no longer exists |
| `AlwaysProp` (`always()`) | ✅ Always | ✅ Always | Included even in partial reloads |
| `DeferProp` (`defer()`) | ❌ Never (in `deferredProps`) | ✅ Separate XHR | Client fetches per group |
| `OnceProp` (`once()`) | ✅ First time | ❌ Skip if in `X-Inertia-Except-Once-Props` (full XHR visits only; ignored on partial reloads) | Client caches; supports `.as()`, `.until()`, `.fresh()` |
| `MergeProp` (`merge()`) | ✅ Yes | ✅ Yes | Populates `mergeProps`/`prependProps`/`deepMergeProps` |

---

## Project structure

```
symfony-inertia-bundle/
├── AGENTS.md
├── CLAUDE.md
├── composer.json
├── config/
│   ├── definition.php          ← DefinitionConfigurator schema
│   └── services.yaml           ← explicit service definitions, no autowire
├── src/
│   ├── InertiaBundle.php       ← AbstractBundle: configure() + loadExtension()
│   ├── Service/
│   │   └── Inertia.php         ← render(), share(), shareOnce(), flash(), errors(),
│   │                              clearHistory(), encryptHistory(), preserveFragment(),
│   │                              version(), handleExceptionsUsing(),
│   │                              optional(), always(), defer(), once(),
│   │                              merge(), deepMerge(), location(), scroll()
│   ├── Response/
│   │   └── InertiaResponse.php ← builds page object, handles HTML vs JSON
│   ├── EventListener/
│   │   ├── InertiaListener.php ← kernel.request (detect, version check) + kernel.response (302→303)
│   │   ├── InertiaExceptionListener.php ← kernel.exception → handleExceptionsUsing() callback
│   │   └── InertiaValidationListener.php ← kernel.exception → ValidationFailedException → errors + 303 back
│   ├── Exception/
│   │   └── ExceptionResponse.php ← DTO passed to the handleExceptionsUsing() callback
│   ├── Props/
│   │   ├── AlwaysProp.php
│   │   ├── OptionalProp.php
│   │   ├── DeferProp.php
│   │   ├── OnceProp.php
│   │   ├── MergeProp.php
│   │   └── ScrollProp.php
│   ├── Ssr/
│   │   ├── SsrGatewayInterface.php
│   │   ├── NullSsrGateway.php
│   │   ├── HttpSsrGateway.php
│   │   └── SsrResponse.php
│   ├── Command/
│   │   ├── StartSsrCommand.php
│   │   ├── StopSsrCommand.php
│   │   └── CheckSsrCommand.php
│   ├── Testing/
│   │   └── AssertableInertiaPage.php
│   ├── Twig/
│   │   └── InertiaTwigExtension.php   ← inertia(page), inertiaHead(page); SSR-aware
│   └── Controller/
│       └── AbstractInertiaController.php
└── tests/
    ├── Unit/
    └── Functional/
        └── Protocol/
            ├── FirstVisitTest.php
            ├── XhrVisitTest.php
            ├── HistoryFlagsTest.php
            ├── AssetVersionTest.php
            ├── PartialReloadTest.php
            ├── ResetPropsTest.php
            ├── DeferredPropsTest.php
            ├── MergePropsPathTest.php
            ├── MatchPropsOnTest.php
            ├── ScrollPropsTest.php
            ├── FlashTest.php
            ├── ValidationErrorsTest.php
            ├── LocationTest.php
            ├── SsrTest.php
            └── RedirectTest.php
```

---

## Testing standards

- PHPUnit 10.5+ or 11+, minimum **95% coverage** on `src/`
- Test naming: `test<Behavior><Condition><ExpectedResult>` in camelCase, **no underscores** — php-cs-fixer
  (`php_unit_method_casing`) rewrites underscores and CI runs it in dry-run mode
- Reference implementation for expected behavior: `/home/tony/Documents/tony/inertia-laravel` (branch `3.x`,
  `src/PropsResolver.php`, `tests/PropsResolverTest.php`)
- `WebTestCase` for functional/protocol tests
- Always test both paths: Inertia XHR AND normal HTML request
- Check HTTP headers precisely with `assertResponseHasHeader()`
- Never test private methods directly — test through public API

---

## Git — commit format

```
feat(protocol): implement partial reload support
fix(listener): correct 302→303 conversion after DELETE
test(functional): add asset versioning 409 conflict test
refactor(response): extract page object builder
chore(ci): add Symfony 8.0 to test matrix
docs(readme): add Flex installation instructions
```

---

## v2 → v3 migration — changes implemented on branch `3.x`

**v3.0.0 released 2026-03-25.** Branch `3.x` targets v3. The following breaking changes from v2 are **already implemented**:

| Area | v2 | v3 (implemented) |
|------|-----|------------------|
| HTML embedding | `<div id="app" data-page='...'>` attribute | `<script data-page="app" type="application/json">` tag ✅ |
| JSON escaping | `JSON_HEX_TAG\|APOS\|AMP\|QUOT` | `JSON_HEX_TAG` only (sufficient in script context) ✅ |
| `clearHistory` | Always present, even if `false` | Omitted when `false` ✅ |
| `encryptHistory` | Always present, even if `false` | Omitted when `false` ✅ |
| `sharedProps` | — | Top-level list of `share()` keys (config `expose_shared_prop_keys`) ✅ |
| `X-Inertia-Redirect` | — | `409` for redirects whose target contains `#` ✅ |
| `preserveFragment` | — | Page object flag, omitted when `false` ✅ |

**Known gaps vs inertia-laravel 3.5.1 (audit 2026-10-12):**

| Area | inertia-laravel 3.x | Status |
|------|---------------------|--------|
| Nested closures / prop objects inside arrays | Resolved recursively | ❌ Serialized as `{}` |
| Dot-notation partial reloads (`auth.user`) | Supported | ❌ Prop dropped |
| `handleExceptionsUsing()` | Applies to first visits (HTML) too | ❌ XHR only |
| Optional/defer props in except-only partial reloads | Resolved | ❌ Not resolved |
| `X-Inertia-Version` header on version-mismatch 409 | Since 3.1.1 | ❌ |
| `defer(rescue: true)` + `rescuedProps` | Since 3.1.0 | ❌ |

---

## Available slash commands

```
/plan   <feature>   → plan before coding (use in plan mode: Shift+Tab)
/tdd    <feature>   → TDD cycle: Red → Green → Refactor
/review             → full review: protocol + bundle conventions + coverage

# Reference skills (auto-loaded by context, or invoke manually)
/inertia-protocol   → HTTP protocol: page object, headers, status codes, filtering rules
/inertia-props      → All prop types PHP API: optional, always, defer, once, merge, scroll
```

## Workflow

```
Shift+Tab (plan mode) → /plan <feature> → review plan → /clear
Shift+Tab (work mode) → /tdd <feature> → /review → commit
```