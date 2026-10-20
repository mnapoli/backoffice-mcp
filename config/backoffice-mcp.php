<?php declare(strict_types=1);

return [

    /*
     * The MCP servers exposed over HTTP, each behind a bearer token or an OAuth login.
     *
     * A server without authentication is not exposed at all, and its path answers 404: a server is
     * off in every environment until its token is set there, or OAuth turned on. Generate a token
     * with `openssl rand -hex 32`.
     *
     *     'admin' => [
     *         'server' => App\Mcp\AdminMcpServer::class,
     *         'path' => 'mcp/admin',
     *         'auth' => [
     *             'token' => env('ADMIN_MCP_TOKEN'),
     *         ],
     *         'requests_per_minute' => 60, // optional
     *     ],
     *
     * Behind OAuth, users log in with their account in the application, and the gate decides who
     * can connect. It needs Laravel Passport, see the README.
     *
     *     'support' => [
     *         'server' => App\Mcp\SupportMcpServer::class,
     *         'path' => 'mcp/support',
     *         'auth' => [
     *             'oauth' => true,
     *             'gate' => 'useSupportMcp',
     *         ],
     *     ],
     */
    'servers' => [
        //
    ],

];
