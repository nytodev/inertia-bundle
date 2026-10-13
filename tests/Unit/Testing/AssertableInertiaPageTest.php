<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Unit\Testing;

use Nytodev\InertiaBundle\Testing\AssertableInertiaPage;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class AssertableInertiaPageTest extends TestCase
{
    // ── fromResponse ─────────────────────────────────────────────────────────

    public function testFromResponseWithXhrResponseParsesPageObject(): void
    {
        $response = $this->makeXhrResponse(['component' => 'Users', 'props' => ['name' => 'John'], 'url' => '/users', 'version' => null, 'clearHistory' => false, 'encryptHistory' => false]);
        $page = AssertableInertiaPage::fromResponse($response);
        $page->component('Users');
    }

    public function testFromResponseWithHtmlResponseParsesPageObject(): void
    {
        $response = $this->makeHtmlResponse(['component' => 'Home', 'props' => [], 'url' => '/', 'version' => null, 'clearHistory' => false, 'encryptHistory' => false]);
        $page = AssertableInertiaPage::fromResponse($response);
        $page->component('Home');
    }

    public function testFromResponseWithNonInertiaHtmlResponseThrowsAssertionError(): void
    {
        $response = new Response('<html><body>Hello</body></html>', 200, ['Content-Type' => 'text/html']);
        $this->expectException(AssertionFailedError::class);
        AssertableInertiaPage::fromResponse($response);
    }

    public function testFromResponseHtmlWithHexEscapedJsonDecodesCorrectly(): void
    {
        $data = ['component' => 'Test', 'props' => ['label' => 'A & B'], 'url' => '/', 'version' => null, 'clearHistory' => false, 'encryptHistory' => false];
        $response = $this->makeHtmlResponse($data);
        $page = AssertableInertiaPage::fromResponse($response);
        $page->where('label', 'A & B');
    }

    // ── component ────────────────────────────────────────────────────────────

    public function testComponentWithMatchingComponentPasses(): void
    {
        $page = $this->makePage(['component' => 'Dashboard', 'props' => []]);
        $page->component('Dashboard');
        $this->addToAssertionCount(1);
    }

    public function testComponentWithWrongComponentThrowsAssertionError(): void
    {
        $page = $this->makePage(['component' => 'Dashboard', 'props' => []]);
        $this->expectException(AssertionFailedError::class);
        $page->component('Users');
    }

    // ── url ──────────────────────────────────────────────────────────────────

    public function testUrlWithMatchingUrlPasses(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => [], 'url' => '/dashboard']);
        $page->url('/dashboard');
        $this->addToAssertionCount(1);
    }

    public function testUrlWithQueryStringPasses(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => [], 'url' => '/users?page=2']);
        $page->url('/users?page=2');
        $this->addToAssertionCount(1);
    }

    public function testUrlWithWrongUrlThrowsAssertionError(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => [], 'url' => '/dashboard']);
        $this->expectException(AssertionFailedError::class);
        $page->url('/other');
    }

    // ── version ──────────────────────────────────────────────────────────────

    public function testVersionWithMatchingVersionPasses(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => [], 'version' => 'abc123']);
        $page->version('abc123');
        $this->addToAssertionCount(1);
    }

    public function testVersionWithNullVersionPasses(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => [], 'version' => null]);
        $page->version(null);
        $this->addToAssertionCount(1);
    }

    public function testVersionWithWrongVersionThrowsAssertionError(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => [], 'version' => 'v1']);
        $this->expectException(AssertionFailedError::class);
        $page->version('v2');
    }

    // ── has ──────────────────────────────────────────────────────────────────

    public function testHasWithExistingPropPasses(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['name' => 'John']]);
        $page->has('name');
        $this->addToAssertionCount(1);
    }

    public function testHasWithMissingPropThrowsAssertionError(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['name' => 'John']]);
        $this->expectException(AssertionFailedError::class);
        $page->has('email');
    }

    public function testHasWithDotNotationPasses(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['user' => ['name' => 'John']]]);
        $page->has('user.name');
        $this->addToAssertionCount(1);
    }

    public function testHasWithDotNotationMissingPropThrowsAssertionError(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['user' => ['name' => 'John']]]);
        $this->expectException(AssertionFailedError::class);
        $page->has('user.email');
    }

    public function testHasWithArrayIndexPasses(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['users' => [['name' => 'John']]]]);
        $page->has('users.0.name');
        $this->addToAssertionCount(1);
    }

    public function testHasWithNullValuePropPasses(): void
    {
        // A prop can legitimately have a null value and still "exist"
        $page = $this->makePage(['component' => 'X', 'props' => ['token' => null]]);
        $page->has('token');
        $this->addToAssertionCount(1);
    }

    // ── missing ──────────────────────────────────────────────────────────────

    public function testMissingWithAbsentPropPasses(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['name' => 'John']]);
        $page->missing('email');
        $this->addToAssertionCount(1);
    }

    public function testMissingWithExistingPropThrowsAssertionError(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['name' => 'John']]);
        $this->expectException(AssertionFailedError::class);
        $page->missing('name');
    }

    public function testMissingWithDotNotationPasses(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['user' => ['name' => 'John']]]);
        $page->missing('user.email');
        $this->addToAssertionCount(1);
    }

    public function testMissingWithDotNotationExistingPropThrowsAssertionError(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['user' => ['name' => 'John']]]);
        $this->expectException(AssertionFailedError::class);
        $page->missing('user.name');
    }

    // ── where ────────────────────────────────────────────────────────────────

    public function testWhereWithMatchingScalarValuePasses(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['name' => 'John']]);
        $page->where('name', 'John');
        $this->addToAssertionCount(1);
    }

    public function testWhereWithNonMatchingScalarValueThrowsAssertionError(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['name' => 'John']]);
        $this->expectException(AssertionFailedError::class);
        $page->where('name', 'Jane');
    }

    public function testWhereWithClosureReceivesActualValue(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['count' => 42]]);
        $received = null;
        $page->where('count', static function (mixed $value) use (&$received): void {
            $received = $value;
        });
        self::assertSame(42, $received);
    }

    public function testWhereWithClosureReturningFalseFails(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['count' => 42]]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Inertia prop [count] was marked as invalid using a closure.');

        $page->where('count', static fn (int $count): bool => $count > 100);
    }

    public function testWhereWithClosureReturningTruePasses(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['count' => 42]]);

        $page->where('count', static fn (int $count): bool => $count > 10);

        $this->addToAssertionCount(1);
    }

    public function testWhereWithClosureReturningNonBoolIsIgnored(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['count' => 42]]);

        $page->where('count', static fn (int $count): int => 0);

        $this->addToAssertionCount(1);
    }

    public function testWhereAllWithClosureReturningFalseFails(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['name' => 'John', 'count' => 42]]);

        $this->expectException(AssertionFailedError::class);

        $page->whereAll(['name' => 'John', 'count' => static fn (int $count): bool => 0 === $count]);
    }

    public function testWhereWithDotNotationWorks(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['user' => ['age' => 30]]]);
        $page->where('user.age', 30);
        $this->addToAssertionCount(1);
    }

    public function testWhereWithIntegerArrayIndexWorks(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['items' => ['a', 'b', 'c']]]);
        $page->where('items.1', 'b');
        $this->addToAssertionCount(1);
    }

    public function testWhereWithNullValuePasses(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['token' => null]]);
        $page->where('token', null);
        $this->addToAssertionCount(1);
    }

    // ── whereAll ─────────────────────────────────────────────────────────────

    public function testWhereAllWithAllMatchingValuesPasses(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['name' => 'John', 'age' => 30]]);
        $page->whereAll(['name' => 'John', 'age' => 30]);
        $this->addToAssertionCount(1);
    }

    public function testWhereAllWithOneMismatchThrowsAssertionError(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['name' => 'John', 'age' => 30]]);
        $this->expectException(AssertionFailedError::class);
        $page->whereAll(['name' => 'John', 'age' => 99]);
    }

    // ── count ────────────────────────────────────────────────────────────────

    public function testCountWithCorrectCountPasses(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['users' => ['a', 'b', 'c']]]);
        $page->count('users', 3);
        $this->addToAssertionCount(1);
    }

    public function testCountWithWrongCountThrowsAssertionError(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['users' => ['a', 'b']]]);
        $this->expectException(AssertionFailedError::class);
        $page->count('users', 5);
    }

    public function testCountOnNonArrayPropThrowsAssertionError(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['name' => 'John']]);
        $this->expectException(AssertionFailedError::class);
        $page->count('name', 4);
    }

    // ── dd / dump ─────────────────────────────────────────────────────────────

    public function testDumpReturnsInstance(): void
    {
        $page = $this->makePage(['component' => 'X', 'props' => ['key' => 'val']]);
        ob_start();
        $result = $page->dump();
        ob_end_clean();
        self::assertSame($page, $result);
    }

    // ── edge cases ────────────────────────────────────────────────────────────

    public function testFromResponseWithNonArrayJsonThrowsAssertionError(): void
    {
        $response = new Response('null', 200, ['X-Inertia' => 'true', 'Content-Type' => 'application/json']);
        $this->expectException(AssertionFailedError::class);
        AssertableInertiaPage::fromResponse($response);
    }

    public function testHasWithNonArrayIntermediateNodeThrowsAssertionError(): void
    {
        // 'name' is a scalar; trying to access 'name.nested' must fail
        $page = $this->makePage(['component' => 'X', 'props' => ['name' => 'John']]);
        $this->expectException(AssertionFailedError::class);
        $page->has('name.nested');
    }

    public function testWhereWithNonArrayIntermediateNodeThrowsAssertionError(): void
    {
        // 'name' is a scalar; resolveProp cannot traverse further
        $page = $this->makePage(['component' => 'X', 'props' => ['name' => 'John']]);
        $this->expectException(AssertionFailedError::class);
        $page->where('name.nested', 'x');
    }

    // ── fluent interface ──────────────────────────────────────────────────────

    public function testFluentInterfaceAllMethodsReturnSameInstance(): void
    {
        $page = $this->makePage([
            'component' => 'Users',
            'props' => ['name' => 'John', 'items' => ['a', 'b']],
            'url' => '/users',
            'version' => null,
        ]);

        $result = $page
            ->component('Users')
            ->url('/users')
            ->version(null)
            ->has('name')
            ->missing('email')
            ->where('name', 'John')
            ->whereAll(['name' => 'John'])
            ->count('items', 2);

        self::assertSame($page, $result);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     */
    private function makePage(array $data): AssertableInertiaPage
    {
        return AssertableInertiaPage::fromResponse($this->makeXhrResponse($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function makeXhrResponse(array $data): Response
    {
        return new Response(
            (string) json_encode($data),
            200,
            ['X-Inertia' => 'true', 'Content-Type' => 'application/json'],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function makeHtmlResponse(array $data): Response
    {
        $json = (string) json_encode($data, \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_AMP | \JSON_HEX_QUOT);
        $html = "<html><body><script data-page=\"app\" type=\"application/json\">{$json}</script><div id=\"app\"></div></body></html>";

        return new Response($html, 200, ['Content-Type' => 'text/html']);
    }
}
