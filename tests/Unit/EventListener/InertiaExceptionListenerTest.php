<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Unit\EventListener;

use Nytodev\InertiaBundle\EventListener\InertiaExceptionListener;
use Nytodev\InertiaBundle\Exception\ExceptionResponse;
use Nytodev\InertiaBundle\Response\InertiaResponse;
use Nytodev\InertiaBundle\Service\Inertia;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class InertiaExceptionListenerTest extends TestCase
{
    private Inertia $inertia;
    private InertiaExceptionListener $listener;
    private HttpKernelInterface $kernel;

    protected function setUp(): void
    {
        $requestStack = new RequestStack();
        $twig = new Environment(new ArrayLoader([
            'base.html.twig' => '<html><head>{{ inertiaHead(page) }}</head><body>{{ inertia(page) }}</body></html>',
        ]));
        $inertiaResponse = new InertiaResponse($twig, 'base.html.twig');

        $this->inertia = new Inertia($requestStack, $inertiaResponse, null);
        $this->listener = new InertiaExceptionListener($this->inertia);
        $this->kernel = $this->createMock(HttpKernelInterface::class);
    }

    private function makeEvent(\Throwable $throwable, ?Request $request = null): ExceptionEvent
    {
        $request ??= Request::create('/error');

        return new ExceptionEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
    }

    public function testOnKernelExceptionNoCallbackDoesNotSetResponse(): void
    {
        $event = $this->makeEvent(new \RuntimeException('Boom'));

        $this->listener->onKernelException($event);

        self::assertFalse($event->hasResponse());
    }

    public function testOnKernelExceptionCallbackDoesNotRenderDoesNotSetResponse(): void
    {
        $this->inertia->handleExceptionsUsing(static function (ExceptionResponse $e): void {
            // callback exists but does not call render()
        });

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/error'));
        $inertia = new Inertia(
            $requestStack,
            new InertiaResponse(
                new Environment(new ArrayLoader([
                    'base.html.twig' => '<html><head></head><body></body></html>',
                ])),
                'base.html.twig',
            ),
            null,
        );
        $inertia->handleExceptionsUsing(static function (ExceptionResponse $e): void {
            // does not render
        });
        $listener = new InertiaExceptionListener($inertia);

        $event = $this->makeEvent(new \RuntimeException('Boom'), Request::create('/error'));
        $listener->onKernelException($event);

        self::assertFalse($event->hasResponse());
    }

    public function testOnKernelExceptionCallbackRendersComponentSetsResponse(): void
    {
        $request = Request::create('/error', 'GET', [], [], [], ['HTTP_X_INERTIA' => 'true']);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $inertia = new Inertia(
            $requestStack,
            new InertiaResponse(
                new Environment(new ArrayLoader([
                    'base.html.twig' => '<html><body></body></html>',
                ])),
                'base.html.twig',
            ),
            null,
        );
        $inertia->handleExceptionsUsing(static function (ExceptionResponse $e): void {
            $e->render('Error/Index', ['status' => $e->statusCode()]);
        });

        $listener = new InertiaExceptionListener($inertia);
        $event = $this->makeEvent(new \RuntimeException('Boom'), $request);
        $listener->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());
    }

    public function testOnKernelExceptionHttpException404Preserves404StatusCode(): void
    {
        $request = Request::create('/error', 'GET', [], [], [], ['HTTP_X_INERTIA' => 'true']);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $inertia = new Inertia(
            $requestStack,
            new InertiaResponse(
                new Environment(new ArrayLoader([
                    'base.html.twig' => '<html><body></body></html>',
                ])),
                'base.html.twig',
            ),
            null,
        );
        $inertia->handleExceptionsUsing(static function (ExceptionResponse $e): void {
            $e->render('Error/Index', ['status' => $e->statusCode()]);
        });

        $listener = new InertiaExceptionListener($inertia);
        $event = $this->makeEvent(new HttpException(404, 'Not Found'), $request);
        $listener->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(404, $response->getStatusCode());
    }

    public function testOnKernelExceptionCallbackReturnValueExceptionResponseAlsoSetsResponse(): void
    {
        $request = Request::create('/error', 'GET', [], [], [], ['HTTP_X_INERTIA' => 'true']);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $inertia = new Inertia(
            $requestStack,
            new InertiaResponse(
                new Environment(new ArrayLoader([
                    'base.html.twig' => '<html><body></body></html>',
                ])),
                'base.html.twig',
            ),
            null,
        );

        // Callback returns the ExceptionResponse (fluent style)
        $inertia->handleExceptionsUsing(static function (ExceptionResponse $e): ExceptionResponse {
            return $e->render('Error/Index');
        });

        $listener = new InertiaExceptionListener($inertia);
        $event = $this->makeEvent(new \RuntimeException('Boom'), $request);
        $listener->onKernelException($event);

        self::assertTrue($event->hasResponse());
    }

    public function testOnKernelExceptionInertiaXhrReturnsJsonResponse(): void
    {
        $request = Request::create('/error', 'GET', [], [], [], ['HTTP_X_INERTIA' => 'true']);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $inertia = new Inertia(
            $requestStack,
            new InertiaResponse(
                new Environment(new ArrayLoader([
                    'base.html.twig' => '<html><body></body></html>',
                ])),
                'base.html.twig',
            ),
            null,
        );
        $inertia->handleExceptionsUsing(static function (ExceptionResponse $e): void {
            $e->render('Error/Index');
        });

        $listener = new InertiaExceptionListener($inertia);
        $event = $this->makeEvent(new \RuntimeException('Boom'), $request);
        $listener->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->has('X-Inertia'));
    }
}
