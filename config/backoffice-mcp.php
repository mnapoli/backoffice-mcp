<?php declare(strict_types=1);

return [

    /*
     * The MCP servers exposed over HTTP, each behind its own bearer token.
     *
     * A server without authentication is not exposed at all, and its path answers 404: a server is
     * off in every environment until its token is set there. Generate one with
     * `openssl rand -hex 32`.
     *
     *     'admin' => [
     *         'server' => App\Mcp\AdminMcpServer::class,
     *         'path' => 'mcp/admin',
     *         'auth' => [
     *             'token' => env('ADMIN_MCP_TOKEN'),
     *         ],
     *         'requests_per_minute' => 60, // optional
     *     ],
     */
    'servers' => [
        //
    ],

];
