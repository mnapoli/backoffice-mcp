<?php declare(strict_types=1);

namespace BackofficeMcp\Tests;

use Illuminate\Testing\Fluent\AssertableJson;
use BackofficeMcp\Tests\Fixtures\AdminServer;
use BackofficeMcp\Tests\Fixtures\SupportServer;
use BackofficeMcp\Tools\SchemaTool;

class SchemaToolTest extends TestCase
{
    public function test_it_describes_every_whitelisted_resource(): void
    {
        AdminServer::tool(SchemaTool::class)
            ->assertOk()
            ->assertStructuredContent(fn(AssertableJson $json) => $json
                ->has('resources', 4)
                ->has('resources.teams', fn(AssertableJson $teams) => $teams
                    ->where('columns.name', 'varchar')
                    ->where('filters', ['id' => 'exact', 'name' => 'partial', 'plan' => 'exact', 'created_at' => 'operator'])
                    ->where('sorts', ['id', 'name', 'created_at'])
                    ->where('includes.owner', 'users')
                    ->where('soft_deletes', true)
                    ->etc())
                ->where('resources.projects.soft_deletes', false));
    }

    public function test_it_does_not_mention_a_table_or_a_column_that_is_not_whitelisted(): void
    {
        $response = SupportServer::tool(SchemaTool::class)->assertOk();

        $response->assertDontSee('secret');
        $response->assertDontSee('users');
        $response->assertDontSee('deployments');
        $response->assertSee('customers');
    }
}
