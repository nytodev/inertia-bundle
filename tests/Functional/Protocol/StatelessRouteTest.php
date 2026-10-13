<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional\Protocol;

use Nytodev\InertiaBundle\Testing\AssertableInertiaPage;
use Nytodev\InertiaBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * On `_stateless` routes the session exists but must not be touched (Symfony throws
 * UnexpectedSessionUsageException in debug): flash, errors and history flags fall back
 * to memory and still reach a render in the same request.
 */
final class StatelessRouteTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
    }

    public function testXhrVisitOnStatelessRouteRendersInMemoryState(): void
    {
        $this->client->request('GET', '/test/stateless-page', [], [], ['HTTP_X_INERTIA' => 'true']);

        self::assertResponseIsSuccessful();
        $this->assertInertia($this->client->getResponse(), static function (AssertableInertiaPage $page): void {
            $page->where('errors', ['email' => 'Invalid email']);
        });

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(['status' => 'saved'], $data['flash']);
        self::assertTrue($data['clearHistory']);
    }

    public function testFirstVisitOnStatelessRouteRendersInMemoryState(): void
    {
        $this->client->request('GET', '/test/stateless-page');

        self::assertResponseIsSuccessful();
        $this->assertInertia($this->client->getResponse(), static function (AssertableInertiaPage $page): void {
            $page->where('errors', ['email' => 'Invalid email']);
        });
    }
}
