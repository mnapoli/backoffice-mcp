<?php declare(strict_types=1);

namespace BackofficeMcp\Tests;

use InvalidArgumentException;
use LogicException;
use BackofficeMcp\Catalog;
use BackofficeMcp\Resource;
use BackofficeMcp\Tests\Fixtures\Project;
use BackofficeMcp\Tests\Fixtures\Team;
use stdClass;

class CatalogTest extends TestCase
{
    public function test_a_resource_is_named_after_its_table_by_default(): void
    {
        $this->assertSame('teams', Resource::for(Team::class)->name);
        $this->assertSame('customers', Resource::for(Team::class, 'customers')->name);
    }

    public function test_a_resource_must_be_an_eloquent_model(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Resource::for(stdClass::class); // @phpstan-ignore argument.type
    }

    public function test_two_resources_cannot_share_a_name(): void
    {
        $this->expectExceptionObject(new LogicException('Two resources are named "teams".'));

        new Catalog([
            Resource::for(Team::class)->columns('id'),
            Resource::for(Team::class)->columns('name'),
        ], 25);
    }

    public function test_a_relation_must_point_to_a_resource_that_exists(): void
    {
        $this->expectExceptionObject(new LogicException('The relation "projects" of resource "teams" points to a resource "projects" that does not exist.'));

        new Catalog([
            Resource::for(Team::class)->columns('id')->include('projects', 'projects'),
        ], 25);
    }

    public function test_a_resource_must_declare_columns(): void
    {
        $this->expectExceptionObject(new LogicException('The resource "projects" declares no columns.'));

        new Catalog([Resource::for(Project::class)], 25);
    }
}
