<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\EventListener;

use Nytodev\InertiaBundle\Service\Inertia;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Converts ValidationFailedException — thrown by #[MapRequestPayload] /
 * #[MapQueryString] before the controller runs, or manually — into the
 * standard Inertia error flow: store the errors in session, redirect back
 * (303 See Other), so the next render injects them as the 'errors' prop.
 *
 * Mirrors Laravel's ValidationException-to-redirect handling, which Symfony
 * has no framework equivalent for. Requires symfony/validator.
 *
 * Runs before InertiaExceptionListener so validation errors never reach the
 * generic handleExceptionsUsing() callback.
 */
final class InertiaValidationListener
{
    public function __construct(
        private readonly Inertia $inertia,
    ) {
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$request->headers->has('X-Inertia')) {
            return;
        }

        // Errors only survive the redirect through the session — without one,
        // Inertia::errors() falls back to in-memory storage that the next
        // request never sees. On _stateless routes the session exists but must
        // not be touched (UnexpectedSessionUsageException in debug). In both
        // cases, let Symfony return its default 4xx instead.
        if (!$request->hasSession() || $request->attributes->get('_stateless', false)) {
            return;
        }

        $validationException = $this->unwrapValidationException($event->getThrowable());

        if (null === $validationException) {
            return;
        }

        $this->inertia->errors($this->firstMessagePerField($validationException->getViolations()));

        $target = $request->headers->get('referer');
        if (null === $target || '' === $target || !$this->isSafeRedirectTarget($target, $request->getHost())) {
            $target = $request->getUri();
        }

        $event->setResponse(new RedirectResponse($target, Response::HTTP_SEE_OTHER));
        $event->allowCustomResponseCode();
    }

    /**
     * The Referer header is client-controlled: honoring it blindly as redirect
     * target is an open-redirect vector (same class as CVE-2017-16652 in
     * Security\Http). Only same-host absolute URLs and relative paths pass.
     */
    private function isSafeRedirectTarget(string $target, string $requestHost): bool
    {
        $host = parse_url($target, \PHP_URL_HOST);

        if (false === $host) {
            return false;
        }

        if (null === $host) {
            return str_starts_with($target, '/') && !str_starts_with($target, '//');
        }

        return 0 === strcasecmp($host, $requestHost);
    }

    /**
     * MapRequestPayload wraps the ValidationFailedException in an HttpException
     * (422 by default); a manual throw reaches the kernel unwrapped.
     */
    private function unwrapValidationException(\Throwable $throwable): ?ValidationFailedException
    {
        if ($throwable instanceof ValidationFailedException) {
            return $throwable;
        }

        $previous = $throwable->getPrevious();

        if ($throwable instanceof HttpExceptionInterface && $previous instanceof ValidationFailedException) {
            return $previous;
        }

        return null;
    }

    /**
     * Laravel behavior: one string per field — the first violation message wins.
     *
     * @return array<string, string>
     */
    private function firstMessagePerField(ConstraintViolationListInterface $violations): array
    {
        $errors = [];

        foreach ($violations as $violation) {
            $errors[$violation->getPropertyPath()] ??= (string) $violation->getMessage();
        }

        return $errors;
    }
}
