# Flash & validation errors

## Flash messages

Flash a value into `page.props.flash` for the next Inertia render. The value survives redirects (POST → 303 → GET) via the session and is consumed automatically — it will not appear on subsequent renders.

```php
#[Route('/profile', methods: ['POST'])]
public function update(Request $request, Inertia $inertia): Response
{
    // ... handle update

    $inertia->flash('success', 'Profile updated successfully.');
    return new RedirectResponse('/profile');
}
```

On the next `GET /profile` render, `page.props.flash` will contain:

```json
{ "success": "Profile updated successfully." }
```

Multiple flash keys can be set before the redirect:

```php
$inertia->flash('success', 'Saved.');
$inertia->flash('count', 3);
```

> When no session is available (stateless routes), flash data is kept in memory and only available within the same request lifecycle.

---

## Validation errors

Persist validation errors to be auto-injected into `page.props.errors` on the next render. Follows the same PRG (Post/Redirect/Get) pattern as flash messages.

```php
#[Route('/register', methods: ['POST'])]
public function register(Request $request, Inertia $inertia): Response
{
    $errors = $this->validate($request);

    if (!empty($errors)) {
        $inertia->errors($errors);
        return new RedirectResponse('/register');
    }

    // ...
}
```

On the next `GET /register`, `page.props.errors` will contain:

```json
{ "email": "This email is already taken.", "password": "Too short." }
```

### Automatic interception of MapRequestPayload / MapQueryString

> Requires `symfony/validator`.

When a controller argument mapped with `#[MapRequestPayload]` or `#[MapQueryString]` fails validation, Symfony throws an `HttpException` wrapping a `ValidationFailedException` (422 for `MapRequestPayload`, 404 for `MapQueryString`) — before the controller runs. On Inertia requests (`X-Inertia` header present), the bundle intercepts it automatically:

1. Each violation is converted to one message per field (first message wins, like Laravel).
2. The errors are stored in the session.
3. A `303 See Other` redirect is returned to the `Referer` — only same-host URLs and relative paths are honored (open-redirect protection); anything else falls back to the current URL.
4. The next render injects them as `page.props.errors`.

No code needed — this matches the [Inertia validation flow](https://inertiajs.com/docs/v3/the-basics/validation) out of the box:

```php
#[Route('/users', methods: ['POST'])]
public function create(#[MapRequestPayload] CreateUserPayload $payload, Inertia $inertia): Response
{
    // Only reached when the payload is valid.
}
```

A `ValidationFailedException` thrown manually from a controller is intercepted the same way. Non-Inertia requests are left untouched (Symfony's default 4xx behavior is preserved).

> The interception also requires a session (enabled by default in Symfony apps): errors survive the redirect through it. On sessionless setups and on `_stateless` routes (e.g. behind a `stateless: true` firewall), the listener steps aside and Symfony's default 4xx response is returned.

> Without `symfony/validator`, the listener is not registered at all (its definition is removed at compile time), and denormalization type errors surface as a `PartialDenormalizationException` that the bundle does not convert. Install the validator to use `#[MapRequestPayload]` with Inertia forms.

To disable the interception globally, set `inertia.intercept_validation_errors: false` — the listener is then removed from the container and Symfony's default 4xx behavior applies everywhere. To opt out for a specific case only, register your own `kernel.exception` listener with a priority higher than 16 and set a response before the bundle does.

### Named error bags

Group errors from multiple forms on the same page using named bags:

```php
$inertia->errors(['email' => 'Invalid.'], 'loginForm');
$inertia->errors(['name' => 'Required.'], 'profileForm');
```

Props will be:

```json
{
    "errors": {
        "loginForm":   { "email": "Invalid." },
        "profileForm": { "name": "Required." }
    }
}
```

### X-Inertia-Error-Bag header

When the client sends `X-Inertia-Error-Bag: myBag`, the bundle wraps the `default` bag under the given key:

```json
{ "errors": { "myBag": { "email": "Invalid." } } }
```

This allows a page with multiple independent forms to scope errors without naming the bag server-side.

---

## Symfony Form integration

To bridge Symfony Form validation with Inertia errors, add an event listener on `kernel.response` (or handle in the controller):

```php
use Symfony\Component\Form\FormInterface;

private function handleForm(FormInterface $form, Inertia $inertia): bool
{
    if ($form->isSubmitted() && !$form->isValid()) {
        $errors = [];
        foreach ($form->getErrors(deep: true) as $error) {
            $field = $error->getOrigin()?->getName() ?? 'global';
            $errors[$field] = $error->getMessage();
        }
        $inertia->errors($errors);
        return false;
    }
    return true;
}
```
