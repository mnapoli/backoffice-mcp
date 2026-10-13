<?php declare(strict_types=1);

namespace BackofficeMcp;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\Enums\FilterOperator;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The resources of one server, and what can be done with them: build a query from the arguments
 * of a tool call, and turn the rows it returns into what the MCP client receives.
 *
 * Every argument here comes straight from the MCP client and is checked against the whitelist
 * before it is used. `InvalidArgumentException` means the client asked for something outside it,
 * and is reported back to the client. `LogicException` means the whitelist itself is wrong.
 */
final readonly class Catalog
{
    /** @var array<string, Resource> */
    private array $resources;

    /**
     * @param  iterable<Resource>  $resources
     */
    public function __construct(iterable $resources, private int $maxIncludedRows)
    {
        $byName = [];
        foreach ($resources as $resource) {
            if (isset($byName[$resource->name])) {
                throw new LogicException(sprintf('Two resources are named "%s".', $resource->name));
            }
            if ($resource->getColumns() === []) {
                throw new LogicException(sprintf('The resource "%s" declares no columns.', $resource->name));
            }
            $byName[$resource->name] = $resource;
        }

        foreach ($byName as $resource) {
            foreach ($resource->getIncludes() as $relation => $target) {
                if (! isset($byName[$target])) {
                    throw new LogicException(sprintf('The relation "%s" of resource "%s" points to a resource "%s" that does not exist.', $relation, $resource->name, $target));
                }
            }
        }

        $this->resources = $byName;
    }

    /**
     * @return array<string, Resource>
     */
    public function all(): array
    {
        return $this->resources;
    }

    public function find(string $name): Resource
    {
        if (! isset($this->resources[$name])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown resource "%s". The resources that exist are: %s.',
                $name,
                implode(', ', array_keys($this->resources)),
            ));
        }

        return $this->resources[$name];
    }

    /**
     * Build the query for a set of tool arguments. Anything outside the whitelist makes the query
     * fail rather than run.
     *
     * The client is free to send an object where a list is meant, so none of the arrays can be
     * typed as a list.
     *
     * @param  array<array-key, string>  $fields  columns to select, defaulting to every whitelisted column
     * @param  array<string, mixed>  $filters  column => value
     * @param  array<array-key, string>  $sorts  column names, prefixed with `-` for descending
     * @param  array<array-key, string>  $includes  relation names
     * @param  array<array-key, string>  $counts  relation names, returned as a `<relation>_count` column
     * @param  string|null  $trashed  null, "with" or "only"
     * @return QueryBuilder<Model>
     */
    public function query(Resource $resource, array $fields = [], array $filters = [], array $sorts = [], array $includes = [], array $counts = [], ?string $trashed = null): QueryBuilder
    {
        // The caller's own keys are dropped first: `withCount()` reads them as relation names, so
        // a `{"truncate": "users"}` object would put a name the whitelist below never looks at
        // exactly where the relation name is expected.
        $includes = array_values($includes);
        $counts = array_values($counts);

        // Every relation is checked against the whitelist before anything else happens. An
        // unwhitelisted name would otherwise reach `Model::__call`, which forwards to the query
        // builder, where a name like `truncate` is not a read at all.
        foreach ([...$includes, ...$counts] as $relation) {
            $this->related($resource, $relation);
        }

        $key = $resource->keyName();

        $fields = $fields === [] ? $resource->getColumns() : $fields;
        $fields = array_values(array_unique([...$fields, ...$this->keysRequiredBy($resource, $includes, $counts)]));

        $request = Request::create('/', 'GET', [
            'filter' => $filters,
            'sort' => implode(',', $this->tieBrokenSorts($sorts, $key)),
            'fields' => ['_' => implode(',', $fields)],
        ]);

        $query = QueryBuilder::for($resource->model, $request)
            ->allowedFields(...$resource->getColumns())
            ->allowedFilters(...$this->allowedFilters($resource))
            ->allowedSorts(...array_unique([...$resource->getSorts(), $key]));

        foreach ($includes as $relation) {
            $related = $this->related($resource, $relation);
            $query->with([$relation => function (Relation $builder) use ($related) {
                $builder->select(array_map($builder->qualifyColumn(...), $related->getColumns()));

                if (! $this->isToMany($builder)) {
                    return;
                }

                // A to-many relation is unbounded otherwise: the row limit caps the parent rows,
                // and every one of them would then load all of its related rows. Newest first,
                // because the window the limit compiles to has no ordering of its own, and "the
                // most recent 25" is an answer where an arbitrary 25 is not.
                $builder->orderByDesc($builder->qualifyColumn($related->keyName()));

                // Only these three turn a limit on an eager load into a per-parent one. Anywhere
                // else it forwards to the query builder and compiles to a plain LIMIT over the
                // whole load: 25 rows to whichever parents came back first, every other parent
                // looking as though it has none. Leaving the cap off is the safer miss, since the
                // response budget still bounds the response and no row lies about what it has.
                if ($builder instanceof HasOneOrMany || $builder instanceof BelongsToMany || $builder instanceof HasOneOrManyThrough) {
                    $builder->limit($this->maxIncludedRows);
                }
            }]);
        }

        if ($counts !== []) {
            $query->withCount($counts);
        }

        if ($trashed !== null) {
            if (! $resource->usesSoftDeletes()) {
                throw new InvalidArgumentException(sprintf('Rows of "%s" are never soft-deleted, so "trashed" does not apply to them.', $resource->name));
            }

            if ($trashed === 'only') {
                $query->onlyTrashed(); // @phpstan-ignore method.notFound (which model this is, is only known at runtime)
            } else {
                $query->withTrashed(); // @phpstan-ignore method.notFound
            }
        }

        return $query;
    }

    /**
     * Turn a row into the array the MCP client receives. The whitelist is applied here a second
     * time, so that a column that is not listed cannot leave the process even if something else
     * managed to load it.
     *
     * @return array<string, mixed>
     */
    public function present(Resource $resource, Model $model): array
    {
        // The whitelist is the only thing deciding what a row contains, so the model's own
        // serialisation rules are cleared first: `$appends` would run accessors over columns that
        // were never selected, and `$hidden` or `$visible` are tuned for another audience and
        // would drop columns this whitelist does want.
        $attributes = $model->setAppends([])->setVisible([])->setHidden([])->attributesToArray();

        $row = array_intersect_key($attributes, array_flip($resource->getColumns()));

        foreach ($resource->getIncludes() as $relation => $resourceName) {
            $countKey = Str::snake($relation) . '_count';
            $count = $model->getAttributes()[$countKey] ?? null;
            if (is_numeric($count)) {
                $row[$countKey] = (int) $count;
            }

            if (! $model->relationLoaded($relation)) {
                continue;
            }

            $related = $this->find($resourceName);
            $value = $model->getRelation($relation);

            $row[$relation] = match (true) {
                $value instanceof Collection => $value->map(fn(Model $model) => $this->present($related, $model))->all(),
                $value instanceof Model => $this->present($related, $value),
                default => null,
            };
        }

        return $row;
    }

    /**
     * Those of the given relations that load many rows for each parent row, which are the ones
     * whose cost multiplies out.
     *
     * @param  array<array-key, string>  $includes
     * @return list<string>
     */
    public function toManyIncludes(Resource $resource, array $includes): array
    {
        $model = $resource->newModel();

        return array_values(array_filter(array_values($includes), function (string $relation) use ($resource, $model): bool {
            // Against the whitelist before the name reaches the model, as in `query()`
            $this->related($resource, $relation);

            return $this->isToMany($model->{$relation}());
        }));
    }

    /**
     * @param  Relation<*, *, *>  $relationship
     */
    private function isToMany(Relation $relationship): bool
    {
        // The to-one types are listed and everything else counts as many, so a relation type
        // nobody thought about is counted against the related-row budget rather than slipping
        // past it. Listing the many types instead fails open: a `hasManyThrough` is not a
        // `HasOneOrMany` at all, and would have loaded every related row of every parent.
        return ! ($relationship instanceof BelongsTo          // MorphTo extends this
            || $relationship instanceof HasOne
            || $relationship instanceof MorphOne
            || $relationship instanceof HasOneThrough);
    }

    /**
     * @return list<AllowedFilter>
     */
    private function allowedFilters(Resource $resource): array
    {
        $filters = [];

        foreach ($resource->getFilters() as $column => $type) {
            $filters[] = match ($type) {
                Filter::Exact => AllowedFilter::exact($column),
                Filter::Partial => AllowedFilter::partial($column),
                Filter::Operator => AllowedFilter::operator($column, FilterOperator::DYNAMIC),
            };
        }

        return $filters;
    }

    /**
     * Paging with `offset` only lands on a consistent set of rows when the ordering is total, so
     * every sort ends on the primary key. Without that tiebreaker, two pages of the same query are
     * free to come back in a different order, showing some rows twice and never showing others.
     * It doubles as the default sort: no sort at all leaves `ORDER BY` off the query entirely.
     *
     * @param  array<array-key, string>  $sorts  column names, prefixed with `-` for descending
     * @return list<string>
     */
    private function tieBrokenSorts(array $sorts, string $key): array
    {
        $sorts = array_values($sorts);
        $columns = array_map(fn(string $sort) => ltrim($sort, '-'), $sorts);

        if (in_array($key, $columns, true)) {
            return $sorts;
        }

        return [...$sorts, $key];
    }

    /**
     * Eager loading matches rows on keys, so those have to be selected whatever the caller asked
     * for in `fields`.
     *
     * @param  list<string>  $includes
     * @param  list<string>  $counts
     * @return list<string>
     */
    private function keysRequiredBy(Resource $resource, array $includes, array $counts): array
    {
        if ($includes === [] && $counts === []) {
            return [];
        }

        $model = $resource->newModel();
        $keys = [$model->getKeyName()];

        foreach ($includes as $relation) {
            $relationship = $model->{$relation}();

            $keys[] = match (true) {
                $relationship instanceof BelongsTo => $relationship->getForeignKeyName(),
                $relationship instanceof HasOneOrMany => $relationship->getLocalKeyName(),
                $relationship instanceof BelongsToMany => $relationship->getParentKeyName(),
                default => $model->getKeyName(),
            };
        }

        return $keys;
    }

    private function related(Resource $resource, string $relation): Resource
    {
        $includes = $resource->getIncludes();

        if (! isset($includes[$relation])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown relation "%s" on resource "%s". The relations that can be included are: %s.',
                $relation,
                $resource->name,
                $includes === [] ? 'none' : implode(', ', array_keys($includes)),
            ));
        }

        return $this->find($includes[$relation]);
    }
}
