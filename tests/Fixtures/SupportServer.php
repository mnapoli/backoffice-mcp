<?php declare(strict_types=1);

namespace BackofficeMcp\Tests\Fixtures;

use BackofficeMcp\Filter;
use BackofficeMcp\McpServer;
use BackofficeMcp\Resource;

/**
 * A second audience, which sees less: no users, no deployment logs.
 */
class SupportServer extends McpServer
{
    protected string $name = 'Support';

    protected string $description = 'The customers of Acme, for its support team.';

    public int $maxLimit = 10;

    protected function resources(): array
    {
        return [
            Resource::for(Team::class, 'customers')
                ->columns('id', 'name', 'plan')
                ->filter('name', Filter::Partial)
                ->include('projects', 'projects'),
            Resource::for(Project::class)
                ->columns('id', 'team_id', 'name'),
        ];
    }
}
