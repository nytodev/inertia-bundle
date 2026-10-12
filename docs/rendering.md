# Rendering

## Basic render

Inject `Nytodev\InertiaBundle\Service\Inertia` and call `render()`:

```php
use Nytodev\InertiaBundle\Service\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class UserController
{
    #[Route('/users')]
    public function index(Inertia $inertia): Response
    {
        return $inertia->render('Users/Index', [
            'users' => $this->repository->findAll(),
        ]);
    }
}
```

The first argument is the component name as the JavaScript adapter expects it (e.g. `Users/Index` maps to `resources/js/Pages/Users/Index.vue`).

## AbstractInertiaController

Extend `AbstractInertiaController` to get `$this->renderInertia()` and `$this->inertia` without manual injection:

```php
use Nytodev\InertiaBundle\Controller\AbstractInertiaController;

class UserController extends AbstractInertiaController
{
    #[Route('/users')]
    public function index(): Response
    {
        return $this->renderInertia('Users/Index', [
            'users' => $this->repository->findAll(),
        ]);
    }
}
```

## Shared props

Props shared across every render for the duration of the request lifecycle. Typically set in an event listener on `kernel.request`.

```php
// src/EventListener/InertiaShareListener.php
use Nytodev\InertiaBundle\Service\Inertia;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class InertiaShareListener
{
    public function __construct(private readonly Inertia $inertia) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        $this->inertia->share('auth', [
            'user' => $this->security->getUser()?->getUserIdentifier(),
        ]);
        $this->inertia->share('locale', $event->getRequest()->getLocale());
    }
}
```

### Shared once props

Sent once per client session and cached by the browser. Ideal for static reference data (feature flags, app config).

```php
$inertia->shareOnce('appConfig', fn () => $this->buildConfig());
```

The client sends `X-Inertia-Except-Once-Props` to request a fresh value.

## Prop evaluation order

1. Shared props are merged with component props (`array_merge` — component wins on key conflict).
2. Closures are resolved lazily at render time.
3. Special prop types (`OptionalProp`, `DeferProp`, etc.) are resolved according to their own rules — see [Prop types](props.md).
