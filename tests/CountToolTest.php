<?php declare(strict_types=1);

namespace BackofficeMcp\Tests;

use BackofficeMcp\Tests\Fixtures\AdminServer;
use BackofficeMcp\Tests\Fixtures\Team;
use BackofficeMcp\Tools\CountTool;

class CountToolTest extends TestCase
{
    public function test_it_counts_the_rows_matching_a_filter(): void
    {
        Team::create(['name' => 'One', 'plan' => 'free']);
        Team::create(['name' => 'Two', 'plan' => 'free']);
        Team::create(['name' => 'Three', 'plan' => 'pro']);

        AdminServer::tool(CountTool::class, ['resource' => 'teams'])
            ->assertOk()
            ->assertStructuredContent(['resource' => 'teams', 'count' => 3]);

        AdminServer::tool(CountTool::class, ['resource' => 'teams', 'filter' => ['plan' => 'free']])
            ->assertOk()
            ->assertStructuredContent(['resource' => 'teams', 'count' => 2]);
    }

    public function test_it_counts_soft_deleted_rows_only_when_they_are_asked_for(): void
    {
        Team::create(['name' => 'Live']);
        Team::create(['name' => 'Gone'])->delete();

        AdminServer::tool(CountTool::class, ['resource' => 'teams'])->assertStructuredContent(['resource' => 'teams', 'count' => 1]);
        AdminServer::tool(CountTool::class, ['resource' => 'teams', 'trashed' => 'with'])->assertStructuredContent(['resource' => 'teams', 'count' => 2]);
        AdminServer::tool(CountTool::class, ['resource' => 'teams', 'trashed' => 'only'])->assertStructuredContent(['resource' => 'teams', 'count' => 1]);
    }

    public function test_it_rejects_a_resource_and_a_filter_that_are_not_whitelisted(): void
    {
        AdminServer::tool(CountTool::class, ['resource' => 'team_user'])->assertHasErrors(['Unknown resource']);
        AdminServer::tool(CountTool::class, ['resource' => 'teams', 'filter' => ['secret' => 'x']])->assertHasErrors();
    }
}
