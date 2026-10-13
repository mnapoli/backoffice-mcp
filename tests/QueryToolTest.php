<?php declare(strict_types=1);

namespace BackofficeMcp\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use BackofficeMcp\Tests\Fixtures\AdminServer;
use BackofficeMcp\Tests\Fixtures\Deployment;
use BackofficeMcp\Tests\Fixtures\Project;
use BackofficeMcp\Tests\Fixtures\SupportServer;
use BackofficeMcp\Tests\Fixtures\Team;
use BackofficeMcp\Tests\Fixtures\User;
use BackofficeMcp\Tools\QueryTool;

class QueryToolTest extends TestCase
{
    public function test_it_returns_the_whitelisted_columns_of_a_resource(): void
    {
        Team::create(['name' => 'Acme', 'secret' => 'hunter2']);

        AdminServer::tool(QueryTool::class, ['resource' => 'teams'])
            ->assertOk()
            ->assertStructuredContent(fn(AssertableJson $json) => $json
                ->where('resource', 'teams')
                ->where('returned', 1)
                ->has('rows.0', fn(AssertableJson $row) => $row
                    ->where('name', 'Acme')
                    ->hasAll(['id', 'plan', 'owner_id', 'created_at', 'deleted_at'])
                    ->missing('secret'))
                ->missing('next_offset'));
    }

    public function test_the_models_own_serialisation_rules_do_not_decide_what_a_row_contains(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'x']);

        // `email` is in `$hidden` but whitelisted, `display_name` is appended but not whitelisted,
        // `password` is neither
        AdminServer::tool(QueryTool::class, ['resource' => 'users'])
            ->assertOk()
            ->assertStructuredContent(fn(AssertableJson $json) => $json
                ->has('rows.0', fn(AssertableJson $row) => $row
                    ->where('email', 'alice@example.com')
                    ->missing('display_name')
                    ->missing('password')
                    ->etc())
                ->etc());
    }

    public function test_a_row_loaded_outside_the_query_builder_is_still_narrowed_to_the_whitelist(): void
    {
        $team = Team::create(['name' => 'Acme', 'secret' => 'hunter2']);
        $server = $this->app->make(AdminServer::class, ['transport' => new FakeTransporter]);
        $catalog = $server->catalog();

        $row = $catalog->present($catalog->find('teams'), Team::findOrFail($team->id));

        $this->assertArrayNotHasKey('secret', $row);
        $this->assertSame('Acme', $row['name']);
    }

    public function test_it_rejects_a_resource_a_column_a_filter_a_sort_and_a_relation_that_are_not_whitelisted(): void
    {
        Team::create(['name' => 'Acme']);

        AdminServer::tool(QueryTool::class, ['resource' => 'team_user'])->assertHasErrors();
        AdminServer::tool(QueryTool::class, ['resource' => 'teams', 'fields' => ['secret']])->assertHasErrors();
        AdminServer::tool(QueryTool::class, ['resource' => 'teams', 'filter' => ['secret' => 'x']])->assertHasErrors();
        AdminServer::tool(QueryTool::class, ['resource' => 'teams', 'sort' => ['secret']])->assertHasErrors();
        AdminServer::tool(QueryTool::class, ['resource' => 'teams', 'include' => ['secrets']])->assertHasErrors();
    }

    public function test_a_relation_name_is_never_called_on_the_model_before_it_is_whitelisted(): void
    {
        Team::create(['name' => 'One']);
        Team::create(['name' => 'Two']);

        // Not a relation but a query builder method, which `Model::__call` would happily forward to
        foreach (['truncate', 'delete', 'forceDelete'] as $name) {
            AdminServer::tool(QueryTool::class, ['resource' => 'teams', 'include' => [$name]])->assertHasErrors();
            AdminServer::tool(QueryTool::class, ['resource' => 'teams', 'counts' => [$name]])->assertHasErrors();

            // The same name in the key position of an object, which is where `withCount()` reads
            // its relation names from. The key is dropped and only the whitelisted value is read.
            AdminServer::tool(QueryTool::class, ['resource' => 'teams', 'include' => [$name => 'projects']])->assertOk();
            AdminServer::tool(QueryTool::class, ['resource' => 'teams', 'counts' => [$name => 'projects']])
                ->assertOk()
                ->assertStructuredContent(fn(AssertableJson $json) => $json->has('rows.0.projects_count')->etc());
        }

        $this->assertSame(2, Team::count());
    }

    public function test_each_server_reads_through_its_own_whitelist(): void
    {
        Team::create(['name' => 'Acme', 'plan' => 'pro']);

        SupportServer::tool(QueryTool::class, ['resource' => 'customers'])
            ->assertOk()
            ->assertStructuredContent(fn(AssertableJson $json) => $json
                ->has('rows.0', fn(AssertableJson $row) => $row->hasAll(['id', 'name', 'plan'])->missing('created_at'))
                ->etc());

        SupportServer::tool(QueryTool::class, ['resource' => 'teams'])->assertHasErrors();
        SupportServer::tool(QueryTool::class, ['resource' => 'users'])->assertHasErrors();
        SupportServer::tool(QueryTool::class, ['resource' => 'customers', 'filter' => ['plan' => 'pro']])->assertHasErrors();
    }

    public function test_an_included_relation_is_read_through_its_own_whitelist(): void
    {
        $owner = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'x']);
        Team::create(['name' => 'Acme', 'owner_id' => $owner->id]);

        AdminServer::tool(QueryTool::class, ['resource' => 'teams', 'include' => ['owner']])
            ->assertOk()
            ->assertStructuredContent(fn(AssertableJson $json) => $json
                ->where('rows.0.owner.email', 'alice@example.com')
                ->missing('rows.0.owner.password')
                ->etc());
    }

    public function test_it_includes_a_relation_and_counts_it(): void
    {
        $team = Team::create(['name' => 'Acme']);
        Project::create(['team_id' => $team->id, 'name' => 'Website']);

        AdminServer::tool(QueryTool::class, ['resource' => 'teams', 'include' => ['projects'], 'counts' => ['projects']])
            ->assertOk()
            ->assertStructuredContent(fn(AssertableJson $json) => $json
                ->where('rows.0.projects.0.name', 'Website')
                ->where('rows.0.projects_count', 1)
                ->etc());
    }

    public function test_it_caps_the_rows_of_an_included_relation_per_parent(): void
    {
        // Two parents, because with one there is nothing to tell a cap of 2 per parent apart from
        // a plain `limit 2` over the whole eager load: under a global limit the second team would
        // come back with no projects at all
        foreach (['One', 'Two'] as $name) {
            $team = Team::create(['name' => $name]);
            foreach (range(1, 4) as $i) {
                Project::create(['team_id' => $team->id, 'name' => "Project $i"]);
            }
        }

        $this->tool(['maxIncludedRows' => 2], [
            'resource' => 'teams',
            'include' => ['projects'],
            'counts' => ['projects'],
        ])
            ->assertOk()
            ->assertStructuredContent(fn(AssertableJson $json) => $json
                ->count('rows.0.projects', 2)
                ->count('rows.1.projects', 2)
                // The most recent ones, not an arbitrary two
                ->where('rows.0.projects.0.name', 'Project 4')
                ->where('rows.0.projects.1.name', 'Project 3')
                // The count is of every related row, not only of the ones that came back
                ->where('rows.0.projects_count', 4)
                ->where('rows.1.projects_count', 4)
                ->etc());
    }

    public function test_a_through_relation_is_capped_per_parent(): void
    {
        foreach (['One', 'Two'] as $name) {
            $team = Team::create(['name' => $name]);
            $project = Project::create(['team_id' => $team->id, 'name' => 'Website']);
            foreach (range(1, 4) as $ignored) {
                Deployment::create(['project_id' => $project->id]);
            }
        }

        $this->tool(['maxIncludedRows' => 2], ['resource' => 'teams', 'include' => ['deployments']])
            ->assertOk()
            ->assertStructuredContent(fn(AssertableJson $json) => $json
                ->count('rows.0.deployments', 2)
                ->count('rows.1.deployments', 2)
                ->etc());
    }

    public function test_to_one_relations_are_not_counted_against_the_related_row_budget(): void
    {
        $team = Team::create(['name' => 'Acme']);
        Project::create(['team_id' => $team->id, 'name' => 'Website']);

        // owner, latestProject and firstDeployment return one row each: none multiplies out
        $this->tool(['maxIncludedRows' => 25, 'maxTotalIncludedRows' => 1], [
            'resource' => 'teams',
            'include' => ['owner', 'latestProject', 'firstDeployment'],
        ])
            ->assertOk()
            ->assertStructuredContent(fn(AssertableJson $json) => $json
                ->where('rows.0.latestProject.name', 'Website')
                ->etc());
    }

    public function test_it_rejects_a_request_whose_related_rows_would_be_too_many(): void
    {
        Team::create(['name' => 'Acme']);

        // 50 rows x 2 relations x 25 = 2 500
        $this->tool(['maxIncludedRows' => 25, 'maxTotalIncludedRows' => 2_000], [
            'resource' => 'teams',
            'include' => ['projects', 'users'],
            'limit' => 50,
        ])
            ->assertHasErrors(['Retry with "limit" at 40 or below']);

        $this->tool(['maxIncludedRows' => 25, 'maxTotalIncludedRows' => 2_000], [
            'resource' => 'teams',
            'include' => ['projects', 'users'],
            'limit' => 40,
        ])
            ->assertOk();
    }

    public function test_a_page_too_large_to_send_is_trimmed_rather_than_refused(): void
    {
        $team = Team::create(['name' => 'Acme']);
        $project = Project::create(['team_id' => $team->id, 'name' => 'Website']);
        foreach (range(1, 10) as $ignored) {
            Deployment::create(['project_id' => $project->id, 'log' => str_repeat('x', 1_000)]);
        }

        $this->tool(['maxResponseBytes' => 3_500], ['resource' => 'deployments', 'limit' => 10])
            ->assertOk()
            ->assertStructuredContent(fn(AssertableJson $json) => $json
                ->where('returned', 3)
                ->count('rows', 3)
                ->where('next_offset', 3)
                ->where('suggested_limit', 3)
                ->etc());
    }

    public function test_a_page_always_carries_at_least_one_row(): void
    {
        $team = Team::create(['name' => 'Acme']);
        $project = Project::create(['team_id' => $team->id, 'name' => 'Website']);
        Deployment::create(['project_id' => $project->id, 'log' => str_repeat('x', 1_000)]);
        Deployment::create(['project_id' => $project->id]);

        $this->tool(['maxResponseBytes' => 10], ['resource' => 'deployments'])
            ->assertOk()
            ->assertStructuredContent(fn(AssertableJson $json) => $json
                ->where('returned', 1)
                ->where('next_offset', 1)
                ->etc());
    }

    public function test_a_page_that_fits_is_not_told_to_shrink(): void
    {
        Team::create(['name' => 'Acme']);

        AdminServer::tool(QueryTool::class, ['resource' => 'teams'])
            ->assertOk()
            ->assertStructuredContent(fn(AssertableJson $json) => $json->missing('suggested_limit')->missing('next_offset')->etc());
    }

    public function test_it_caps_the_number_of_rows_and_pages_through_them(): void
    {
        foreach (range(1, 15) as $i) {
            Team::create(['name' => "Team $i"]);
        }

        // SupportServer caps at 10 whatever the caller asks for
        SupportServer::tool(QueryTool::class, ['resource' => 'customers', 'limit' => 100])
            ->assertOk()
            ->assertStructuredContent(fn(AssertableJson $json) => $json
                ->where('returned', 10)
                ->where('next_offset', 10)
                ->etc());

        SupportServer::tool(QueryTool::class, ['resource' => 'customers', 'offset' => 10])
            ->assertOk()
            ->assertStructuredContent(fn(AssertableJson $json) => $json
                ->where('returned', 5)
                ->missing('next_offset')
                ->etc());
    }

    public function test_every_query_ends_on_the_primary_key_so_that_paging_is_stable(): void
    {
        DB::enableQueryLog();

        AdminServer::tool(QueryTool::class, ['resource' => 'teams', 'sort' => ['-created_at']])->assertOk();

        $select = collect(DB::getQueryLog())->pluck('query')->first(fn(string $sql) => str_starts_with(Str::lower($sql), 'select'));
        $this->assertStringContainsString('order by created_at desc, id asc', Str::lower(str_replace(['"', '`'], '', $select)));
    }

    public function test_it_filters_exactly_partially_and_by_operator(): void
    {
        Team::create(['name' => 'Acme Corp', 'plan' => 'pro', 'created_at' => '2026-01-15']);
        Team::create(['name' => 'Globex', 'plan' => 'free', 'created_at' => '2026-03-01']);
        Team::create(['name' => 'Initech', 'plan' => 'pro', 'created_at' => '2026-05-01']);

        $names = fn(array $filter) => AdminServer::tool(QueryTool::class, ['resource' => 'teams', 'filter' => $filter, 'fields' => ['name']]);

        $names(['plan' => 'pro'])->assertStructuredContent(fn(AssertableJson $json) => $json->where('returned', 2)->etc());
        $names(['name' => 'acme'])->assertStructuredContent(fn(AssertableJson $json) => $json->where('rows.0.name', 'Acme Corp')->where('returned', 1)->etc());
        $names(['created_at' => '>=2026-02-01'])->assertStructuredContent(fn(AssertableJson $json) => $json->where('returned', 2)->etc());
        $names(['plan' => 'free,pro'])->assertStructuredContent(fn(AssertableJson $json) => $json->where('returned', 3)->etc());
    }

    public function test_it_hides_soft_deleted_rows_unless_they_are_asked_for(): void
    {
        Team::create(['name' => 'Live']);
        Team::create(['name' => 'Gone'])->delete();

        $returned = fn(array $arguments) => AdminServer::tool(QueryTool::class, ['resource' => 'teams', ...$arguments]);

        $returned([])->assertStructuredContent(fn(AssertableJson $json) => $json->where('returned', 1)->where('rows.0.name', 'Live')->etc());
        $returned(['trashed' => 'with'])->assertStructuredContent(fn(AssertableJson $json) => $json->where('returned', 2)->etc());
        $returned(['trashed' => 'only'])->assertStructuredContent(fn(AssertableJson $json) => $json->where('returned', 1)->where('rows.0.name', 'Gone')->etc());

        AdminServer::tool(QueryTool::class, ['resource' => 'projects', 'trashed' => 'with'])->assertHasErrors(['never soft-deleted']);
    }

    /**
     * Call the query tool on an AdminServer with some of its limits changed.
     *
     * @param  array<string, int>  $limits
     * @param  array<string, mixed>  $arguments
     */
    private function tool(array $limits, array $arguments): TestResponse
    {
        $this->app->bind(AdminServer::class, function ($app, array $parameters) use ($limits) {
            $server = new AdminServer($parameters['transport']);
            foreach ($limits as $name => $value) {
                $server->{$name} = $value;
            }

            return $server;
        });

        return AdminServer::tool(QueryTool::class, $arguments);
    }
}
