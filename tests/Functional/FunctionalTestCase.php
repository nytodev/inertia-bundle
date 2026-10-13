<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional;

use Nytodev\InertiaBundle\Testing\InertiaAssertions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base class for functional tests.
 *
 * Restores PHP exception handlers after each test to prevent PHPUnit 11
 * from marking tests as risky due to Symfony's ErrorHandler registration.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    use InertiaAssertions;

    /** @var list<callable> */
    private array $exceptionHandlersBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->exceptionHandlersBefore = $this->captureActiveExceptionHandlers();
    }

    protected function tearDown(): void
    {
        $after = $this->captureActiveExceptionHandlers();
        $added = \count($after) - \count($this->exceptionHandlersBefore);
        for ($i = 0; $i < $added; ++$i) {
            restore_exception_handler();
        }
        parent::tearDown();
    }

    /**
     * Captures the currently active exception handler stack.
     *
     * @return list<callable>
     */
    private function captureActiveExceptionHandlers(): array
    {
        $handlers = [];

        while (true) {
            $previous = set_exception_handler(static fn (\Throwable $e) => null);
            restore_exception_handler();

            if (null === $previous) {
                break;
            }

            $handlers[] = $previous;
            restore_exception_handler();
        }

        $handlers = array_reverse($handlers);

        foreach ($handlers as $handler) {
            set_exception_handler($handler);
        }

        return $handlers;
    }
}
