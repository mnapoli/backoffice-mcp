<?php declare(strict_types=1);

namespace BackofficeMcp\Tests;

use BackofficeMcp\Catalog;
use BackofficeMcp\Filter;
use BackofficeMcp\Resource;
use BackofficeMcp\Tests\Fixtures\ComputedColumnServer;
use BackofficeMcp\Tests\Fixtures\Project;
use BackofficeMcp\Tests\Fixtures\Team;
use BackofficeMcp\Tools\CountTool;
use BackofficeMcp\Tools\QueryTool;
use BackofficeMcp\Tools\SchemaTool;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Testing\Fluent\AssertableJson;
use LogicException;

class ComputedColumnTest extends TestCase
{
    public function test_it_returns_an_accessor_without_the_columns_it_reads(): void
    {
        Team::create(['name' => 'Acme', 'secret' => 'hunter2']);

        ComputedColumnServer::tool(QueryTool::class, ['resource' => 'teams'])
            ->assertOk()
            ->assertDontSee('hunter2')
            ->assertStructuredContent(fn(AssertableJson $json) => $json
                ->has('rows.0', fn(AssertableJson $row) => $row
                    ->where('name', 'Acme')
                    ->where('masked_secret', '***r2')
                    ->has('id')
                    ->missing('secret'))
                ->etc());
    }

    public function test_it_returns_only_the_fields_asked_for(): void
    {
        Team::create(['name' => 'Acme', 'secret' => 'hunter2']);

        ComputedColumnServer::tool(QueryTool::class, ['resource' => 'teams', 'fields' => ['masked_secret']])
            ->assertOk()
            ->assertStructuredContent(fn(AssertableJson $json) => $json
                ->has('rows.0', fn(AssertableJson $row) => $row->where('masked_secret', '***r2'))
                ->etc());

        ComputedColumnServer::tool(QueryTool::class, ['resource' => 'teams', 'fields' => ['name']])
            ->assertOk()
            ->assertStructuredContent(fn(AssertableJson $json) => $json
                ->has('rows.0', fn(AssertableJson $row) => $row->where('name', 'Acme'))
                ->etc());
    }

    public function test_asking_for_a_computed_column_does_not_open_up_the_rest_of_the_row(): void
    {
        Team::create(['name' => 'Acme', 'secret' => 'hunter2']);

        ComputedColumnServer::tool(QueryTool::class, ['resource' => 'teams', 'fields' => ['masked_secret', 'secret']])
            ->assertHasErrors();
    }

    public function test_it_returns_an_accessor_of_an_included_relation(): void
    {
        $team = Team::create(['name' => 'Acme', 'secret' => 'hunter2']);
        Project::create(['team_id' => $team->id, 'name' => 'Website']);

        ComputedColumnServer::tool(QueryTool::class, ['resource' => 'projects', 'include' => ['team']])
            ->assertOk()
            ->assertDontSee('hunter2')
            ->assertStructuredContent(fn(AssertableJson $json) => $json
                ->has('rows.0.team', fn(AssertableJson $row) => $row
                    ->where('masked_secret', '***r2')
                    ->where('name', 'Acme')
                    ->has('id')
                    ->missing('secret'))
                ->etc());
    }

    public function test_it_rejects_a_filter_and_a_sort_on_a_computed_column_from_the_client(): void
    {
        Team::create(['name' => 'Acme', 'secret' => 'hunter2']);

        ComputedColumnServer::tool(QueryTool::class, ['resource' => 'teams', 'filter' => ['masked_secret' => 'r2']])->assertHasErrors();
        ComputedColumnServer::tool(QueryTool::class, ['resource' => 'teams', 'sort' => ['masked_secret']])->assertHasErrors();
    }

    public function test_it_refuses_to_declare_a_filter_on_a_computed_column(): void
    {
        $catalog = new Catalog([Resource::for(Team::class)->columns('id', 'masked_secret')->filter('masked_secret', Filter::Exact)], 25);
        $this->expectExceptionObject(new LogicException('The column "masked_secret" of resource "teams" is computed: it is not in the database, so it cannot be filtered or sorted on.'));
        $catalog->query($catalog->find('teams'));
    }

    public function test_it_refuses_to_declare_a_sort_on_a_computed_column(): void
    {
        $catalog = new Catalog([Resource::for(Team::class)->columns('id', 'masked_secret')->sorts('masked_secret')], 25);
        $this->expectException(LogicException::class);
        $catalog->query($catalog->find('teams'));
    }

    public function test_the_schema_describes_a_computed_column(): void
    {
        ComputedColumnServer::tool(SchemaTool::class)
            ->assertOk()
            ->assertStructuredContent(fn(AssertableJson $json) => $json
                ->where('resources.teams.columns.masked_secret', 'computed')
                ->etc());
    }

    public function test_a_resource_with_a_computed_column_can_be_counted(): void
    {
        Team::create(['name' => 'Acme']);

        ComputedColumnServer::tool(CountTool::class, ['resource' => 'teams'])
            ->assertOk()
            ->assertStructuredContent(['resource' => 'teams', 'count' => 1]);
    }

    public function test_the_casts_of_the_columns_that_are_not_listed_do_not_run(): void
    {
        $team = Team::create(['name' => 'Acme', 'secret' => 'not encrypted, so decrypting it fails']);
        $model = new class extends Model {
            protected $table = 'teams';
            protected $casts = ['secret' => 'encrypted'];

            /** @return Attribute<string, never> */
            protected function shout(): Attribute
            {
                return Attribute::get(fn(): string => mb_strtoupper((string) $this->getRawOriginal('name')));
            }
        };

        $catalog = new Catalog([Resource::for($model::class, 'teams')->columns('id', 'shout')], 25);
        $resource = $catalog->find('teams');
        $row = $catalog->query($resource)->where('id', $team->id)->firstOrFail();

        $this->assertSame(['id' => $team->id, 'shout' => 'ACME'], $catalog->present($resource, $row));
    }

    public function test_a_column_of_the_table_with_an_accessor_of_the_same_name_is_not_computed(): void
    {
        $model = new class extends Model {
            protected $table = 'teams';

            /** @return Attribute<string, never> */
            protected function name(): Attribute
            {
                return Attribute::get(fn(string $name): string => mb_strtoupper($name));
            }
        };
        $team = Team::create(['name' => 'Acme', 'secret' => 'hunter2']);

        $catalog = new Catalog([Resource::for($model::class, 'teams')->columns('id', 'name')->filter('name', Filter::Exact)], 25);
        $resource = $catalog->find('teams');
        $this->assertSame([], $resource->getComputedColumns());

        $row = $catalog->query($resource, filters: ['name' => 'Acme'])->firstOrFail();

        $this->assertArrayNotHasKey('secret', $row->getAttributes());
        $this->assertSame(['id' => $team->id, 'name' => 'ACME'], $catalog->present($resource, $row));
    }
}
