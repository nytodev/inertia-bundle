<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Testing;

use Symfony\Component\HttpFoundation\Response;

/**
 * Adds assertInertia() to any PHPUnit test case (WebTestCase, KernelTestCase or plain TestCase).
 */
trait InertiaAssertions
{
    /**
     * Works with both XHR (JSON) and HTML (first-visit) responses.
     *
     * @param (callable(AssertableInertiaPage): void)|null $callback
     */
    public static function assertInertia(Response $response, ?callable $callback = null): AssertableInertiaPage
    {
        $page = AssertableInertiaPage::fromResponse($response);

        if (null !== $callback) {
            $callback($page);
        }

        return $page;
    }
}
