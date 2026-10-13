<?php declare(strict_types=1);

namespace BackofficeMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use BackofficeMcp\Resource;

#[Name('query')]
#[Description(<<<'TEXT'
    Reads rows from one resource. Call the "schema" tool first: the columns, filters, sorts and
    relations it lists are the only ones accepted, and anything else is rejected rather than
    ignored.

    Filters are given as an object of column => value. What a value means depends on the filter's
    type in the schema: "exact" matches the value, "partial" matches anywhere in the column, and
    "operator" accepts a leading >, <, >=, <= or <> so that "created_at": ">=2026-01-01" reads as
    a range. A value may also be a comma-separated list, which matches any of them.

    Sorts are column names, prefixed with "-" for descending.

    Results are capped and paginated: "returned" is how many rows are in this page, and when
    "next_offset" comes back there are more, which passing it as "offset" reads. Nothing in the
    response says how many rows match in total; the "count" tool is what answers that.

    A page too large to send comes back short, with a "next_offset" for the rest and a
    "suggested_limit" to pass as "limit" on the next call, so that the discarded rows are not
    fetched again on every page. Only the absence of "next_offset" means the end of the results.

    Included relations are capped too, per row and separately from "limit", so a row that has
    more related rows than the cap comes back with the most recently created of them. Count them
    with "counts", or read them as their own resource, rather than reading an included list as
    complete.
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class QueryTool extends BackofficeTool
{
    protected function run(Request $request): Response|ResponseFactory
    {
        /** @var array{resource: string, fields?: list<string>, filter?: array<string, mixed>, sort?: list<string>, include?: list<string>, counts?: list<string>, trashed?: string, limit?: int, offset?: int} $validated */
        $validated = $request->validate([
            'resource' => ['required', 'string'],
            'fields' => ['array'],
            'fields.*' => ['string'],
            'filter' => ['array'],
            'sort' => ['array'],
            'sort.*' => ['string'],
            'include' => ['array'],
            'include.*' => ['string'],
            'counts' => ['array'],
            'counts.*' => ['string'],
            'trashed' => ['string', 'in:with,only'],
            'limit' => ['integer', 'min:1'],
            'offset' => ['integer', 'min:0'],
        ], [
            'resource.required' => 'You must name the resource to read. Call the "schema" tool to see which ones exist.',
            'trashed.in' => 'The "trashed" argument must be "with" to read live and deleted rows, or "only" to read deleted rows only.',
        ]);

        $catalog = $this->server->catalog();
        $resource = $catalog->find($validated['resource']);
        $includes = $validated['include'] ?? [];

        $limit = min($validated['limit'] ?? $this->server->defaultLimit, $this->server->maxLimit);
        $offset = $validated['offset'] ?? 0;

        if ($error = $this->tooManyRelatedRows($resource, $includes, $limit)) {
            return $error;
        }

        $query = $catalog->query(
            $resource,
            fields: $validated['fields'] ?? [],
            filters: $validated['filter'] ?? [],
            sorts: $validated['sort'] ?? [],
            includes: $includes,
            counts: $validated['counts'] ?? [],
            trashed: $validated['trashed'] ?? null,
        );

        // One row more than asked for, which says whether there is a next page without the extra
        // COUNT query that paginating would run. The eager loads are statements of their own, and
        // they share the request's time budget with the main query.
        /** @var Collection<int, Model> $rows */
        $rows = $this->withQueryTimeout(
            $resource,
            fn() => $query->offset($offset)->limit($limit + 1)->get(),
            statements: 1 + count($includes),
        );

        $hasMore = $rows->count() > $limit;
        /** @var list<array<string, mixed>> $presented */
        $presented = array_values($rows->take($limit)->map(fn(Model $row) => $catalog->present($resource, $row))->all());

        // The query has already run by the time the response can be weighed, so a page too heavy
        // to send is trimmed rather than refused: refusing costs a second query for one answer.
        // Nothing is lost by trimming, since `returned` and `next_offset` already mean "this is a
        // page, there are more" and the ordering is total, so the rest is resumable. That is what
        // separates it from truncating an included list, where fewer rows reads as a row that has
        // fewer of them and there is no cursor to carry on from.
        $fits = $this->rowsThatFit($presented);
        $trimmed = $fits < count($presented);

        $response = [
            'resource' => $resource->name,
            // Not "count": the `count` tool returns one under that name meaning every matching
            // row, and the same key on the same server meaning the size of this page is the kind
            // of number a caller reads a conclusion out of without checking `next_offset`
            'returned' => $fits,
            'rows' => array_slice($presented, 0, $fits),
        ];

        if ($hasMore || $trimmed) {
            $response['next_offset'] = $offset + $fits;
        }

        if ($trimmed) {
            // Trimming silently would make the caller pay for the discarded rows again on every
            // page: it follows `next_offset` with the same `limit`, fetches the same oversized
            // page, and gets the same short one back. Saying so is what lets it stop.
            $response['suggested_limit'] = $fits;
        }

        return Response::structured($response);
    }

    protected function narrowingHint(): string
    {
        return 'Narrow it with a filter, fewer relations, or a smaller limit.';
    }

    /**
     * How many of these rows fit in one response. Rows are not the same size, so the row limits
     * are the wrong unit for what the caller has to read: one row carrying a long text column can
     * weigh as much as hundreds of others.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function rowsThatFit(array $rows): int
    {
        $budget = $this->server->maxResponseBytes;
        $bytes = 0;

        foreach ($rows as $index => $row) {
            $bytes += strlen((string) json_encode($row, JSON_THROW_ON_ERROR));

            // At least one row always comes back, otherwise a row over the whole budget could
            // never be read at all, and `next_offset` would point at it forever
            if ($bytes > $budget && $index > 0) {
                return $index;
            }
        }

        return count($rows);
    }

    /**
     * The absurd-request backstop, which stops a request before any query runs. It is about what
     * would be fetched and hydrated, not what would be sent: `rowsThatFit()` weighs that.
     *
     * @param  array<array-key, string>  $includes
     */
    private function tooManyRelatedRows(Resource $resource, array $includes, int $limit): ?Response
    {
        $relations = count($this->server->catalog()->toManyIncludes($resource, $includes));
        $perRow = $this->server->maxIncludedRows;
        $budget = $this->server->maxTotalIncludedRows;

        if ($relations === 0 || $limit * $relations * $perRow <= $budget) {
            return null;
        }

        return Response::error(sprintf(
            'That asks for up to %d related rows (%d rows x %d relations returning a list x %d each), over the %d one request may fetch. Relations returning a single row are not counted. Retry with "limit" at %d or below, ask for fewer list relations, or use "counts" to get how many there are without the rows.',
            $limit * $relations * $perRow,
            $limit,
            $relations,
            $perRow,
            $budget,
            max(1, intdiv($budget, $relations * $perRow)),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()
                ->description('The resource to read, as named by the "schema" tool.')
                ->required(),

            'fields' => $schema->array()
                ->items($schema->string())
                ->description('Columns to return. Defaults to every column of the resource.'),

            'filter' => $schema->object()
                ->description('Filters as column => value, using only the columns the schema lists as filterable.'),

            'sort' => $schema->array()
                ->items($schema->string())
                ->description('Columns to sort by, each optionally prefixed with "-" for descending.'),

            'include' => $schema->array()
                ->items($schema->string())
                ->description(sprintf(
                    'Relations to load with each row, one level deep. At most %d related rows per row are returned, most recently created first. Asking for more than %d related rows at once (rows x relations returning a list x %1$d) is refused rather than run, so several list relations need a lower "limit".',
                    $this->server->maxIncludedRows,
                    $this->server->maxTotalIncludedRows,
                )),

            'counts' => $schema->array()
                ->items($schema->string())
                ->description('Relations to count, returned as a "<relation>_count" column instead of the rows themselves.'),

            'trashed' => $schema->string()
                ->enum(['with', 'only'])
                ->description('Soft-deleted rows are excluded by default. "with" reads them alongside live rows, "only" reads them alone.'),

            'limit' => $schema->integer()
                ->description(sprintf('Rows to return, at most %d.', $this->server->maxLimit))
                ->default($this->server->defaultLimit),

            'offset' => $schema->integer()
                ->description('Rows to skip, for reading the page after a response that came back with "next_offset".')
                ->default(0),
        ];
    }
}
