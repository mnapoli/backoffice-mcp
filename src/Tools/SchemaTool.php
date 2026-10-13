<?php declare(strict_types=1);

namespace BackofficeMcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use BackofficeMcp\Filter;

#[Name('schema')]
#[Description(<<<'TEXT'
    Lists everything the "query" and "count" tools can read: the resources that exist, and for
    each one its columns with their database types, the columns that can be filtered on and how,
    the columns that can be sorted on, and the relations that can be included. Call this first,
    since anything absent from it is rejected.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class SchemaTool extends BackofficeTool
{
    protected function run(Request $request): Response|ResponseFactory
    {
        $resources = [];

        foreach ($this->server->catalog()->all() as $name => $resource) {
            $model = $resource->newModel();

            $types = collect($model->getConnection()->getSchemaBuilder()->getColumns($model->getTable()))
                ->pluck('type_name', 'name');

            $columns = [];
            foreach ($resource->getColumns() as $column) {
                $columns[$column] = $types->get($column, 'unknown');
            }

            $resources[$name] = [
                'columns' => $columns,
                'filters' => array_map(fn(Filter $filter) => $filter->value, $resource->getFilters()),
                'sorts' => $resource->getSorts(),
                'includes' => $resource->getIncludes(),
                'soft_deletes' => $resource->usesSoftDeletes(),
            ];
        }

        return Response::structured(['resources' => $resources]);
    }
}
