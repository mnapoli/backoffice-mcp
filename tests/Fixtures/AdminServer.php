<?php declare(strict_types=1);

namespace BackofficeMcp\Tests\Fixtures;

use BackofficeMcp\Filter;
use BackofficeMcp\McpServer;
use BackofficeMcp\Resource;

class AdminServer extends McpServer
{
    protected string $name = 'Admin';

    protected function resources(): array
    {
        return [
            Resource::for(Team::class)
                ->columns('id', 'name', 'plan', 'owner_id', 'created_at', 'deleted_at')
                ->filter('id', Filter::Exact)
                ->filter('name', Filter::Partial)
                ->filter('plan', Filter::Exact)
                ->filter('created_at', Filter::Operator)
                ->sorts('id', 'name', 'created_at')
                ->include('owner', 'users')
                ->include('users', 'users')
                ->include('projects', 'projects')
                ->include('latestProject', 'projects')
                ->include('deployments', 'deployments')
                ->include('firstDeployment', 'deployments'),
            Resource::for(User::class)
                ->columns('id', 'name', 'email', 'created_at')
                ->filter('email', Filter::Exact)
                ->sorts('id')
                ->include('teams', 'teams'),
            Resource::for(Project::class)
                ->columns('id', 'team_id', 'name', 'created_at')
                ->filter('team_id', Filter::Exact)
                ->sorts('id')
                ->include('team', 'teams')
                ->include('deployments', 'deployments'),
            Resource::for(Deployment::class)
                ->columns('id', 'project_id', 'status', 'log', 'created_at')
                ->filter('status', Filter::Exact)
                ->sorts('id', 'created_at'),
        ];
    }
}
