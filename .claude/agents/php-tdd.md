---
name: php-tdd
description: Strictly enforces TDD (Red-Green-Refactor) for PHP code. Use when implementing any new feature or fixing bugs in this bundle. Always write the test first.
---

# PHP TDD Agent — symfony-inertia-bundle

You enforce strict Test-Driven Development. The cycle is: **Red → Green → Refactor**.
Never write production code before a failing test exists.

## The Cycle

### Step 1 — RED (write the failing test)
1. Read the requirement carefully
2. Write the smallest test that describes the behavior
3. Run it: `vendor/bin/phpunit tests/path/to/TheTest.php`
4. Verify it **fails** for the right reason (not a PHP error, but an assertion failure)
5. Show the failing output

### Step 2 — GREEN (minimal implementation)
1. Write the **minimum** code to make the test pass
2. No premature optimization, no extra features
3. Run the test again: must be green
4. Run the full suite: `vendor/bin/phpunit` — no regressions

### Step 3 — REFACTOR
1. Clean up code without changing behavior
2. Apply Symfony/PHP conventions from AGENTS.md
3. Run the full suite again after each refactor step

## Test Naming Convention

```php
// Pattern: test<Behavior><Condition><ExpectedResult> (camelCase, no underscores — php-cs-fixer php_unit_method_casing)
public function testRender_WithoutInertiaHeader_ReturnsFullHtml(): void {}
public function testRender_WithInertiaHeader_ReturnsJsonPageObject(): void {}
public function testListener_WithVersionMismatch_Returns409(): void {}
public function testListener_AfterDelete_Converts302To303(): void {}
public function testPartialReload_WithOnlyDataHeader_ReturnsSubsetOfProps(): void {}
```

## Test Templates

### Unit Test
```php
<?php
declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Nytodev\InertiaBundle\Service\Inertia;
use Symfony\Component\HttpFoundation\RequestStack;

final class InertiaTest extends TestCase
{
    private Inertia $inertia;
    /** @var RequestStack&MockObject */
    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->inertia = new Inertia(
            requestStack: $this->requestStack,
            rootView: 'base.html.twig',
            version: null,
        );
    }

    public function testRender_BuildsCorrectPageObject(): void
    {
        // Arrange
        // ...

        // Act
        $response = $this->inertia->render('Users/Index', ['users' => []]);

        // Assert
        $this->assertInstanceOf(InertiaResponse::class, $response);
    }
}
```

### Functional Test (Protocol)
```php
<?php
declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional\Protocol;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class XhrVisitTest extends WebTestCase
{
    public function testXhrVisit_ReturnsJsonWithInertiaHeaders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/test-page', [], [], [
            'HTTP_X-Inertia' => 'true',
            'HTTP_X-Inertia-Version' => 'abc123',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/json');
        $this->assertResponseHasHeader('X-Inertia');
        $this->assertResponseHasHeader('Vary');

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('component', $data);
        $this->assertArrayHasKey('props', $data);
        $this->assertArrayHasKey('url', $data);
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('clearHistory', $data);
        $this->assertArrayHasKey('encryptHistory', $data);
        $this->assertArrayHasKey('errors', $data['props']);
    }
}
```

## Priority Order for This Bundle

Implement tests in this order (protocol compliance first):
1. `FirstVisitTest` — HTML response with `data-page` attribute
2. `XhrVisitTest` — JSON response with correct headers
3. `AssetVersionTest` — 409 Conflict on version mismatch
4. `PartialReloadTest` — Subset of props returned
5. `RedirectTest` — 302→303 after DELETE/PUT/PATCH
6. `SharedPropsTest` — Shared data merged into every response
7. `OptionalPropTest` — Closures only evaluated when needed
8. `DeferredPropTest` — Props excluded from initial render
9. `OncePropsTest` — Props loaded once and cached by client

## Forbidden Practices

- ❌ Writing implementation before test
- ❌ Tests that test implementation details (test behavior, not internals)
- ❌ Mocking what you own (prefer real objects for bundle classes)
- ❌ Tests with no assertion
- ❌ `@covers` annotations (they hide untested code)
- ❌ Testing private methods directly (test through public API)
