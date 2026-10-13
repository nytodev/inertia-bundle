<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Unit\Testing;

use Nytodev\InertiaBundle\Testing\AssertableInertiaPage;
use Nytodev\InertiaBundle\Testing\InertiaAssertions;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class InertiaAssertionsTest extends TestCase
{
    use InertiaAssertions;

    public function testAssertInertiaPassesXhrPageToCallback(): void
    {
        $received = null;

        self::assertInertia($this->xhrResponse('Users/Index'), static function (AssertableInertiaPage $page) use (&$received): void {
            $received = $page;
        });

        self::assertInstanceOf(AssertableInertiaPage::class, $received);
    }

    public function testAssertInertiaReadsHtmlFirstVisit(): void
    {
        $page = ['component' => 'Home', 'props' => ['errors' => []], 'url' => '/', 'version' => null];
        $html = '<script data-page="app" type="application/json">'.json_encode($page, \JSON_HEX_TAG | \JSON_THROW_ON_ERROR).'</script><div id="app"></div>';

        self::assertInertia(new Response($html))->component('Home');
    }

    public function testAssertInertiaWithoutCallbackReturnsChainablePage(): void
    {
        self::assertInertia($this->xhrResponse('Users/Index'))
            ->component('Users/Index')
            ->has('errors');
    }

    public function testAssertInertiaFailsOnNonInertiaResponse(): void
    {
        $this->expectException(AssertionFailedError::class);

        self::assertInertia(new Response('<html><body>Hello</body></html>'));
    }

    public function testAssertInertiaPropagatesCallbackFailure(): void
    {
        $this->expectException(AssertionFailedError::class);

        self::assertInertia($this->xhrResponse('Users/Index'), static function (AssertableInertiaPage $page): void {
            $page->component('Users/Show');
        });
    }

    private function xhrResponse(string $component): Response
    {
        $page = ['component' => $component, 'props' => ['errors' => []], 'url' => '/users', 'version' => null];

        return new Response(json_encode($page, \JSON_THROW_ON_ERROR), 200, ['X-Inertia' => 'true', 'Content-Type' => 'application/json']);
    }
}
