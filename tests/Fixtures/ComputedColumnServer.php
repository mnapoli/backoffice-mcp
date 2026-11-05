<?php declare(strict_types=1);

namespace BackofficeMcp\Tests\Fixtures;

use BackofficeMcp\McpServer;
use BackofficeMcp\Resource;

class ComputedColumnServer extends McpServer
{
    protected string $name = 'Computed columns';

    protected function resources(): array
    {
        return [
            Resource::for(Team::class)->columns('id', 'name', 'masked_secret'),
            Resource::for(Project::class)->columns('id', 'team_id', 'name')->include('team', 'teams'),
        ];
    }
}
