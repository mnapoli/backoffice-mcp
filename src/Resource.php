<?php declare(strict_types=1);

namespace BackofficeMcp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

/**
 * One table exposed through an MCP server, together with the whitelist of what may be read from
 * it. Anything absent from the whitelist is unreachable: a column that is not listed is never
 * selected nor returned, and a filter, sort or relation that is not listed is rejected.
 *
 *     Resource::for(Team::class)
 *         ->columns('id', 'name', 'plan', 'created_at')
 *         ->filter('plan', Filter::Exact)
 *         ->filter('created_at', Filter::Operator)
 *         ->sorts('id', 'created_at')
 *         ->include('users', 'users')
 */
final class Resource
{
    /** @var list<string> */
    private array $columns = [];

    /** @var array<string, Filter> */
    private array $filters = [];

    /** @var list<string> */
    private array $sorts = [];

    /** @var array<string, string> relation name => resource name */
    private array $includes = [];

    /**
     * @param  class-string<Model>  $model
     */
    private function __construct(
        public readonly string $model,
        public readonly string $name,
    ) {}

    /**
     * @param  class-string  $model
     * @param  string|null  $name  the name the MCP client uses, defaulting to the model's table
     */
    public static function for(string $model, ?string $name = null): self
    {
        if (! is_a($model, Model::class, true)) {
            throw new InvalidArgumentException(sprintf('"%s" is not an Eloquent model.', $model));
        }

        return new self($model, $name ?? (new $model)->getTable());
    }

    /**
     * The columns that may be selected and returned. They are also the default SELECT list.
     */
    public function columns(string ...$columns): self
    {
        $this->columns = array_values(array_unique([...$this->columns, ...$columns]));

        return $this;
    }

    public function filter(string $column, Filter $type): self
    {
        $this->filters[$column] = $type;

        return $this;
    }

    /**
     * The columns that may be sorted on.
     */
    public function sorts(string ...$columns): self
    {
        $this->sorts = array_values(array_unique([...$this->sorts, ...$columns]));

        return $this;
    }

    /**
     * Allow a relation to be included in each row, or counted. Its rows are read through the
     * whitelist of the resource named here, never through the model directly.
     *
     * @param  string  $relation  the Eloquent relation method on the model
     * @param  string  $resource  the name of the resource the related rows are read through
     */
    public function include(string $relation, string $resource): self
    {
        $this->includes[$relation] = $resource;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * @return array<string, Filter>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * @return list<string>
     */
    public function getSorts(): array
    {
        return $this->sorts;
    }

    /**
     * @return array<string, string> relation name => resource name
     */
    public function getIncludes(): array
    {
        return $this->includes;
    }

    public function newModel(): Model
    {
        return new $this->model;
    }

    public function keyName(): string
    {
        return $this->newModel()->getKeyName();
    }

    public function usesSoftDeletes(): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($this->model), true);
    }
}
