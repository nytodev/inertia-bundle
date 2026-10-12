# Installation & configuration

## Installation

### With Symfony Flex (recommended)

```bash
composer require nytodev/inertia-bundle
```

Flex registers the bundle automatically.

### Without Symfony Flex

**Step 1** — Install via Composer:

```bash
composer require nytodev/inertia-bundle
```

**Step 2** — Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    Nytodev\InertiaBundle\InertiaBundle::class => ['all' => true],
];
```

## Configuration

Create `config/packages/inertia.yaml`:

```yaml
inertia:
    # Root Twig template rendered on first visit (non-XHR).
    # Must contain {{ inertia(page) }} and {{ inertiaHead(page) }}.
    root_view: base.html.twig

    # Asset version string. When the server value differs from the client's,
    # Inertia triggers a full page reload. Set to null to disable versioning.
    # Accepts any string: a hash, a timestamp, an env var…
    version: null

    # Convert ValidationFailedException (MapRequestPayload/MapQueryString or manual
    # throw) on Inertia requests into the error flow: errors in session + 303 back.
    # Set to false to keep Symfony's default 4xx behavior. Requires symfony/validator.
    intercept_validation_errors: true

    # Globally encrypt browser history state for all Inertia responses.
    # Can be overridden per-render via $inertia->encryptHistory().
    encrypt_history: false

    # Expose shared prop keys as a top-level `sharedProps` field in the page object.
    # Allows the client to distinguish shared props from page-specific ones.
    expose_shared_prop_keys: true

    # Server-Side Rendering — requires symfony/http-client.
    ssr_enabled: false
    ssr_url: 'http://127.0.0.1:13714'
    ssr_bundle: null   # auto-detected from bootstrap/ssr/ssr.mjs or public/build/ssr/ssr.mjs
```

## Root Twig template

Your `root_view` template must output the Inertia root element and, optionally, SSR head tags:

```twig
{# templates/base.html.twig #}
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>My App</title>
    {{ inertiaHead(page) }}
    {% block stylesheets %}{% endblock %}
</head>
<body>
    {{ inertia(page) }}
    {% block javascripts %}{% endblock %}
</body>
</html>
```

- `inertia(page)` — outputs `<script data-page="app" type="application/json">...</script><div id="app"></div>` (or SSR HTML when enabled).
- `inertiaHead(page)` — outputs SSR-rendered `<head>` tags. Safe to include even when SSR is disabled (outputs nothing).

## JavaScript client setup

### With vite-plugin-symfony

Install the adapter and create the app entry point:

```bash
npm install @inertiajs/react react react-dom     # React
npm install @inertiajs/vue3 vue                   # Vue 3
```

```js
// assets/app.jsx  (React) — @inertiajs/react v3
import { createInertiaApp } from '@inertiajs/react'

// Minimal — setup is optional in v3, Inertia calls createRoot() automatically
createInertiaApp({
    resolve: name => {
        const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true })
        return pages[`./Pages/${name}.jsx`]
    },
})

// With explicit setup — still fully supported if you need manual control
// import { createRoot } from 'react-dom/client'
// setup({ el, App, props }) { createRoot(el).render(<App {...props} />) }
```

```js
// assets/app.js  (Vue 3)
import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'

createInertiaApp({
    resolve: name => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })
        return pages[`./Pages/${name}.vue`]
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el)
    },
})
```

### With Webpack Encore

```bash
npm install @inertiajs/react react react-dom     # React
npm install @inertiajs/vue3 vue                  # Vue 3
```

Enable the framework preset in `webpack.config.js`:

```js
Encore
    .addEntry('app', './assets/app.jsx')
    .enableReactPreset()   // React
    // .enableVueLoader()  // Vue 3
```

Webpack does not support `import.meta.glob()` — use `require.context()` instead:

```js
// assets/app.jsx  (React) — @inertiajs/react v3
import { createInertiaApp } from '@inertiajs/react'

createInertiaApp({
    resolve: name => require(`./Pages/${name}`),
    // setup is optional in v3 — Inertia calls createRoot() automatically
})
```

```js
// assets/app.js  (Vue 3)
import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'

createInertiaApp({
    resolve: name => require(`./Pages/${name}`),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el)
    },
})
```
