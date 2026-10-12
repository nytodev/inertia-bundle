# Inertia.js Symfony Bundle

Symfony bundle implementing the [Inertia.js](https://inertiajs.com/) v3 server-side protocol — the Symfony equivalent of [`inertiajs/inertia-laravel`](https://github.com/inertiajs/inertia-laravel).

[![Tests](https://github.com/nytodev/inertia-bundle/actions/workflows/tests.yml/badge.svg)](https://github.com/nytodev/inertia-bundle/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/nytodev/inertia-bundle.svg?style=flat-square)](https://packagist.org/packages/nytodev/inertia-bundle)
[![PHP](https://img.shields.io/packagist/php-v/nytodev/inertia-bundle.svg?style=flat-square)](https://packagist.org/packages/nytodev/inertia-bundle)
[![License](https://img.shields.io/packagist/l/nytodev/inertia-bundle.svg?style=flat-square)](LICENSE)

---

## What is Inertia.js?

Inertia lets you build modern React, Vue, or Svelte single-page applications without building a separate API. You keep your Symfony controllers, routing, middleware, and authentication exactly as they are - controllers just return a component name and props instead of a Twig template.

On the first visit the server returns a full HTML page. On subsequent navigations it returns a lightweight JSON response and the JavaScript adapter swaps the component client-side without a full reload.

---

## Requirements

PHP ^8.1 · Symfony ^6.4 / ^7.4 / ^8.0 · Twig ^3.0

---

## Installation

```bash
composer require nytodev/inertia-bundle
```

Without Symfony Flex, register the bundle manually in `config/bundles.php`:

```php
Nytodev\InertiaBundle\InertiaBundle::class => ['all' => true],
```

---

## Quick start

### 1. Root Twig template

```twig
{# templates/base.html.twig #}
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    {{ inertiaHead(page) }}
    {{ encore_entry_link_tags('app') }}
</head>
<body>
    {{ inertia(page) }}
    {{ encore_entry_script_tags('app') }}
</body>
</html>
```

`inertia(page)` outputs `<script data-page="app" type="application/json">...</script><div id="app"></div>`.  
`inertiaHead(page)` outputs SSR-rendered head tags (safe to include even without SSR).

### 2. Controller

```php
use Nytodev\InertiaBundle\Service\Inertia;

class UserController
{
    #[Route('/users')]
    public function index(Inertia $inertia): Response
    {
        return $inertia->render('Users/Index', [
            'users' => $this->repository->findAll(),
        ]);
    }

    #[Route('/users/{id}')]
    public function show(User $user, Inertia $inertia): Response
    {
        return $inertia->render('Users/Show', [
            'user' => $user,
        ]);
    }
}
```

### 3. JavaScript entry point (React)

```bash
npm install @inertiajs/react react react-dom
```

```js
// assets/app.jsx — @inertiajs/react v3 (setup is optional, Inertia calls createRoot automatically)
import { createInertiaApp } from '@inertiajs/react'

createInertiaApp({
    resolve: name => {
        const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true })
        return pages[`./Pages/${name}.jsx`]
    },
})
```

### 4. A page component

```jsx
// assets/Pages/Users/Index.jsx
export default function UsersIndex({ users }) {
    return (
        <ul>
            {users.map(user => (
                <li key={user.id}>{user.name}</li>
            ))}
        </ul>
    )
}
```

That's it. The bundle handles first-visit HTML, subsequent XHR JSON responses, asset versioning, 302→303 redirect conversion, and partial reloads automatically.

---

## Shared props

Set props that are available on every page (e.g. authenticated user, flash messages):

```php
// src/EventListener/InertiaShareListener.php
class InertiaShareListener
{
    public function __construct(
        private readonly Inertia $inertia,
        private readonly Security $security,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        $this->inertia->share('auth', [
            'user' => $this->security->getUser()?->getUserIdentifier(),
        ]);
    }
}
```

```yaml
# config/services.yaml
App\EventListener\InertiaShareListener:
    tags:
        - { name: kernel.event_listener, event: kernel.request }
```

---

## Prop types

| Method | Behaviour |
|--------|-----------|
| `$inertia->lazy(fn)` / `optional(fn)` | Never sent on full render, only on explicit partial reload |
| `$inertia->always(fn)` | Always sent, even in partial reloads that filter other props |
| `$inertia->defer(fn)` | Fetched by the client in a separate XHR after page load |
| `$inertia->once(fn)` | Sent once, cached by the client |
| `$inertia->merge(fn)` | Merged into the existing client value (infinite scroll) |

```php
return $inertia->render('Feed/Index', [
    'posts'    => $inertia->merge(fn () => $this->postRepo->paginate($page)),
    'stats'    => $inertia->defer(fn () => $this->buildStats()),
    'comments' => $inertia->lazy(fn () => $this->commentRepo->findAll()),
]);
```

---

## Configuration

```yaml
# config/packages/inertia.yaml
inertia:
    root_view: base.html.twig   # Root Twig template (default: base.html.twig)
    version: null               # Asset version string — triggers full reload on change
    intercept_validation_errors: true # Convert ValidationFailedException (MapRequestPayload/MapQueryString or manual throw) into Inertia errors + 303 back
    encrypt_history: false      # Globally encrypt browser history state
    expose_shared_prop_keys: true # Expose shared prop keys as top-level `sharedProps` in page object
    ssr_enabled: false            # Enable SSR (requires symfony/http-client)
    ssr_url: 'http://127.0.0.1:13714'
    ssr_bundle: null              # Path to SSR JS bundle, auto-detected if null
```

---

## Documentation

| Topic | Link |
|-------|------|
| Installation & configuration | [docs/installation.md](docs/installation.md) |
| Rendering & shared props | [docs/rendering.md](docs/rendering.md) |
| All prop types | [docs/props.md](docs/props.md) |
| Asset versioning, redirects, history | [docs/features.md](docs/features.md) |
| Flash & validation errors | [docs/flash-errors.md](docs/flash-errors.md) |
| Server-Side Rendering | [docs/ssr.md](docs/ssr.md) |
| Testing | [docs/testing.md](docs/testing.md) |

---

## License

MIT — see [LICENSE](LICENSE).
