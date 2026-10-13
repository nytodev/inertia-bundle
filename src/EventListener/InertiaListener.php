<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\EventListener;

use Nytodev\InertiaBundle\Service\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Handles two Inertia protocol concerns at the kernel level:
 *
 * kernel.request (priority 20):
 *   - Asset version mismatch on GET → 409 Conflict + X-Inertia-Location header
 *   - Reflash session flash data before returning 409
 *
 * kernel.response (priority 0):
 *   - 302 after PUT/PATCH/DELETE → 303 See Other
 */
final class InertiaListener
{
    public function __construct(
        private readonly Inertia $inertia,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$request->headers->has('X-Inertia')) {
            return;
        }

        if ($request->isMethod('GET')) {
            $serverVersion = $this->inertia->version();

            if (null !== $serverVersion) {
                $clientVersion = $request->headers->get('X-Inertia-Version');

                if (null !== $clientVersion && $clientVersion !== $serverVersion) {
                    // Reflash all session flash data (including 'errors') so it survives
                    // the hard reload that follows a 409 Conflict.
                    if ($request->hasSession() && !$request->attributes->getBoolean('_stateless')) {
                        $session = $request->getSession();
                        if ($session instanceof FlashBagAwareSessionInterface) {
                            $flashes = $session->getFlashBag()->all();
                            foreach ($flashes as $type => $messages) {
                                foreach ($messages as $message) {
                                    $session->getFlashBag()->add($type, $message);
                                }
                            }
                        }
                    }

                    $event->setResponse(new Response('', 409, [
                        'X-Inertia-Location' => $request->getUri(),
                    ]));

                    return;
                }
            }
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        // 302 → 303 conversion for PUT/PATCH/DELETE (must run first).
        if (
            $request->headers->has('X-Inertia')
            && 302 === $response->getStatusCode()
            && \in_array($request->getMethod(), ['PUT', 'PATCH', 'DELETE'], true)
        ) {
            $response->setStatusCode(303);
        }

        // X-Inertia-Redirect: intercept fragment redirects on Inertia XHR requests.
        // Prefetch requests are excluded — they are speculative and the client does not
        // commit to the navigation, so intercepting them would produce unexpected 409s.
        if (
            $request->headers->has('X-Inertia')
            && 'prefetch' !== $request->headers->get('Purpose')
            && $response->isRedirect()
        ) {
            $location = (string) $response->headers->get('Location', '');
            if (str_contains($location, '#')) {
                $event->setResponse(new Response('', 409, ['X-Inertia-Redirect' => $location]));
            }
        }
    }
}
