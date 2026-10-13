# Server-Side Rendering (SSR)

Server-Side Rendering pre-renders the initial page HTML on the server via a Node.js process.
This improves perceived performance and enables search engine indexing.

This bundle communicates with the SSR server over HTTP, regardless of how the SSR bundle was built
(Vite, Webpack Encore, etc.).

---

## Requirements

SSR requires `symfony/http-client` and `pentatrion/vite-bundle`:

```bash
composer require symfony/http-client pentatrion/vite-bundle
```

---

## Setup

### Step 1 — Create the SSR entry point

Create a dedicated entry point for server-side rendering. It uses `createServer` instead of
`createInertiaApp`.

**React** (`assets/ssr.js`):

```js
import { createInertiaApp } from '@inertiajs/react'
import createServer from '@inertiajs/react/server'
import ReactDOMServer from 'react-dom/server'

createServer(page =>
    createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,
        resolve: name => {
            const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true })
            return pages[`./Pages/${name}.jsx`]
        },
        setup: ({ App, props }) => <App {...props} />,
    })
)
```

**Vue 3** (`assets/ssr.js`):

```js
import { createInertiaApp } from '@inertiajs/vue3'
import { createServer } from '@inertiajs/vue3/server'
import { renderToString } from '@vue/server-renderer'
import { createSSRApp, h } from 'vue'

createServer(page =>
    createInertiaApp({
        page,
        render: renderToString,
        resolve: name => {
            const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })
            return pages[`./Pages/${name}.vue`]
        },
        setup: ({ app, props, plugin }) => {
            return createSSRApp({ render: () => h(app, props) }).use(plugin)
        },
    })
)
```

---

### Step 2 — Build the SSR bundle

#### With Vite (recommended)

Create a **dedicated SSR config** — do not reuse `vite.config.js`. `vite-plugin-symfony` tracks
client entry points and will fail when it cannot find their outputs during an SSR-only build.

```js
// vite.ssr.config.js
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
    plugins: [react()],
    ssr: {
        noExternal: ['@inertiajs/react'],
    },
    build: {
        outDir: 'bootstrap/ssr',
    },
})
```

Build both bundles:

```bash
# Build the client bundle (uses vite.config.js)
npx vite build

# Build the SSR bundle → outputs to bootstrap/ssr/
npx vite build --config vite.ssr.config.js --ssr assets/ssr.js
```

Or add scripts to `package.json`:

```json
{
    "scripts": {
        "build":     "vite build && vite build --config vite.ssr.config.js --ssr assets/ssr.js",
        "build:ssr": "vite build --config vite.ssr.config.js --ssr assets/ssr.js",
        "dev":       "vite"
    }
}
```

> The bundle auto-detects `bootstrap/ssr/ssr.mjs` and `bootstrap/ssr/ssr.js` — no PHP config needed.

#### With Webpack Encore

Create a separate webpack config for the server target:

```js
// webpack.ssr.config.js
const Encore = require('@symfony/webpack-encore')

Encore
    .setOutputPath('public/build/ssr/')
    .setPublicPath('/build/ssr')
    .addEntry('ssr', './assets/ssr.js')
    .enableReactPreset()
    .disableSingleRuntimeChunk()

const config = Encore.getWebpackConfig()
config.target = 'node'
config.name   = 'ssr'

module.exports = config
```

```bash
npx webpack --config webpack.ssr.config.js
```

---

### Step 3 — Enable SSR in the bundle config

```yaml
# config/packages/inertia.yaml
inertia:
    ssr_enabled: true
    ssr_url:    'http://127.0.0.1:13714'   # default — keep if using a local Node.js process
    ssr_bundle: null                        # null = auto-detect (see below)
    ssr_ensure_bundle_exists: true          # skip SSR when the bundle file is missing (see below)
    ssr_timeout: null                       # seconds; null = PHP default_socket_timeout (60s)
```

**Auto-detection order** (when `ssr_bundle` is `null`):

| Priority | Path |
|----------|------|
| 1 | `bootstrap/ssr/ssr.mjs` |
| 2 | `public/build/ssr/ssr.mjs` |
| 3 | `public/build/ssr/ssr.js` |

Set `ssr_bundle` explicitly if your output path differs. A relative path is resolved against the
project root (`%kernel.project_dir%`), whatever the working directory of the PHP process:

```yaml
inertia:
    ssr_bundle: 'public/build/ssr/ssr.js'
```

**SSR server on another host or container.** Before each render, the bundle skips SSR when the
bundle file cannot be found, so that a missing build falls back to client-side rendering. When the
Node.js server runs elsewhere and the file is not present next to PHP, disable that check:

```yaml
inertia:
    ssr_url: 'http://ssr:13714'
    ssr_ensure_bundle_exists: false
```

**Timeout.** Set `ssr_timeout` (in seconds) so that a stuck SSR server does not hold every first
visit for the 60s of PHP `default_socket_timeout`. It bounds the whole request; on timeout the
page falls back to client-side rendering and `SsrRenderFailed` is dispatched.

---

### Step 4 — Start the SSR server

```bash
php bin/console inertia:start-ssr
```

The command starts a Node.js process that listens on `ssr_url` and renders pages on demand.
Keep this process running alongside your Symfony app. The command **blocks the terminal** — run
it in a dedicated terminal or in the background:

```bash
php bin/console inertia:start-ssr &
```

---

## Commands

| Command | Description |
|---------|-------------|
| `inertia:start-ssr` | Start the Node.js SSR server |
| `inertia:stop-ssr`  | Stop the SSR server gracefully |
| `inertia:check-ssr` | Check if the SSR server is running and reachable |

### `inertia:start-ssr`

Starts the Node.js SSR process. The bundle path is resolved from `ssr_bundle` config, or
auto-detected from common paths. The process runs in the foreground and streams its output to the
console.

```bash
php bin/console inertia:start-ssr
```

### `inertia:stop-ssr`

Sends a `GET /shutdown` request to the SSR server. The server shuts down gracefully.

```bash
php bin/console inertia:stop-ssr
```

### `inertia:check-ssr`

Sends a `GET /health` request to the SSR server and reports its status.

```bash
php bin/console inertia:check-ssr
```

Example output when running:

```
[OK] SSR server is running at http://127.0.0.1:13714
```

Example output when stopped:

```
[ERROR] SSR server is not reachable at http://127.0.0.1:13714
```

> `inertia:stop-ssr` and `inertia:check-ssr` require `ssr_enabled: true` — they are not
> registered in the container when SSR is disabled.

---

## Twig template

No changes needed to your root template. `inertia()` and `inertiaHead()` automatically use SSR
output when available:

```twig
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    {{ inertiaHead(page) }}   {# outputs SSR <head> tags (title, meta, etc.) #}
</head>
<body>
    {{ inertia(page) }}       {# outputs pre-rendered HTML instead of an empty <div> #}
</body>
</html>
```

If the SSR server is unreachable, the bundle falls back to standard client-side rendering silently.

---

## Production

Do not use `php bin/console inertia:start-ssr` in production — it runs in the foreground.
Use a process manager instead.

### Supervisor

```ini
; /etc/supervisor/conf.d/inertia-ssr.conf
[program:inertia-ssr]
command=node /var/www/app/bootstrap/ssr/ssr.mjs
directory=/var/www/app
autostart=true
autorestart=true
stdout_logfile=/var/log/inertia-ssr.log
stderr_logfile=/var/log/inertia-ssr-error.log
```

```bash
supervisorctl reread
supervisorctl update
supervisorctl start inertia-ssr
```

### systemd

```ini
# /etc/systemd/system/inertia-ssr.service
[Unit]
Description=Inertia SSR Server
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/app
ExecStart=node /var/www/app/bootstrap/ssr/ssr.mjs
Restart=on-failure
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable inertia-ssr
systemctl start inertia-ssr
```

### Deploy workflow

Add SSR build to your deploy script:

```bash
# Install JS dependencies
npm ci

# Build client + SSR bundles
npm run build

# Reload SSR server (with Supervisor)
supervisorctl restart inertia-ssr

# Or with systemd
systemctl restart inertia-ssr
```

---

## Troubleshooting

**`[ERROR] SSR bundle not found`**

The bundle was not built or is not at a detected path. Either build it first (`npm run build`) or
set `ssr_bundle` explicitly in `config/packages/inertia.yaml`.

**Pages are rendered client-side although SSR is enabled**

Without `ssr_throw_on_error`, any SSR failure silently falls back to client-side rendering. Listen
to `Nytodev\InertiaBundle\Ssr\SsrRenderFailed` (or enable `ssr_throw_on_error` in dev) to see the
cause: server unreachable, timeout, or a response that is not `{"head": string[], "body": string}`.
If no event is dispatched, the bundle file was not found (or the path is excluded with `except()`) — check `ssr_bundle`, or set
`ssr_ensure_bundle_exists: false` when the SSR server runs in another container.

**SSR server not starting**

Check that Node.js is installed and accessible:

```bash
node --version
```

Run the SSR bundle directly to see errors:

```bash
node bootstrap/ssr/ssr.mjs
```

**SSR renders blank page**

Ensure `noExternal: ['@inertiajs/react']` (or Vue/Svelte equivalent) is set in your Vite config.
External packages are not bundled for SSR by default and may fail in a Node.js context.

**SSR shows old content after rebuilding the bundle**

The SSR server loads the bundle once at startup. After `npm run build:ssr`, you must restart the
server for changes to take effect:

```bash
php bin/console inertia:stop-ssr
php bin/console inertia:start-ssr
```

**`ssr_url` mismatch**

The `ssr_url` in `config/packages/inertia.yaml` must match the port your SSR server listens on.
The default port is `13714` — check the SSR bundle's `createServer()` call if you changed it.
