<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog;

use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Request handler of the reuse port anchor server.
 *
 * The anchor only exists to make the real HTTP port an added, shareable port. Reusing the
 * application's handler would serve the whole application on the anchor's loopback port.
 */
final class AnchorRequestHandler
{
    public function onRequest(Request $request, Response $response): void
    {
        $response->status(404);
        $response->end();
    }
}
