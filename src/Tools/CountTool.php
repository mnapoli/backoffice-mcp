<?php declare(strict_types=1);

namespace BackofficeMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('count')]
#[Description(<<<'TEXT'
    Counts the rows of one resource that match a filter, without returning any of them. Takes the
    same "resource", "filter" and "trashed" arguments as the "query" tool, and answers questions
    like how many users signed up in a month for the cost of a single number.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class CountTool extends BackofficeTool
{
    protected function run(Request $request): Response|ResponseFactory
    {
        /** @var array{resource: string, filter?: array<string, mixed>, trashed?: string} $validated */
        $validated = $request->validate([
            'resource' => ['required', 'string'],
            'filter' => ['array'],
            'trashed' => ['string', 'in:with,only'],
        ], [
            'resource.required' => 'You must name the resource to count. Call the "schema" tool to see which ones exist.',
            'trashed.in' => 'The "trashed" argument must be "with" to count live and deleted rows, or "only" to count deleted rows only.',
        ]);

        $catalog = $this->server->catalog();
        $resource = $catalog->find($validated['resource']);

        $query = $catalog->query(
            $resource,
            filters: $validated['filter'] ?? [],
            trashed: $validated['trashed'] ?? null,
        );

        $count = $this->withQueryTimeout($resource, fn(): int => $query->count());

        return Response::structured([
            'resource' => $resource->name,
            'count' => $count,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()
                ->description('The resource to count, as named by the "schema" tool.')
                ->required(),

            'filter' => $schema->object()
                ->description('Filters as column => value, using only the columns the schema lists as filterable.'),

            'trashed' => $schema->string()
                ->enum(['with', 'only'])
                ->description('Soft-deleted rows are excluded by default. "with" counts them alongside live rows, "only" counts them alone.'),
        ];
    }
}
