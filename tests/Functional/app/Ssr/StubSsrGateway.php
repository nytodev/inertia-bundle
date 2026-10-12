<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional\app\Ssr;

use Nytodev\InertiaBundle\Ssr\SsrGatewayInterface;
use Nytodev\InertiaBundle\Ssr\SsrResponse;

/**
 * Test double for the SSR gateway.
 * Returns a canned SSR response so functional tests do not need a real Node.js server.
 */
final class StubSsrGateway implements SsrGatewayInterface
{
    public const HEAD = '<title>SSR Title</title><meta name="description" content="SSR">';
    public const BODY = '<div id="app"><p data-ssr="true">Server Rendered</p></div>';

    public function dispatch(array $page): SsrResponse
    {
        return new SsrResponse(self::HEAD, self::BODY);
    }
}
