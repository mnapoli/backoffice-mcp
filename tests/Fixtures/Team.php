<?php declare(strict_types=1);

namespace BackofficeMcp\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Team extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasOne<Project, $this> */
    public function latestProject(): HasOne
    {
        return $this->hasOne(Project::class)->latestOfMany();
    }

    /** @return HasManyThrough<Deployment, Project, $this> */
    public function deployments(): HasManyThrough
    {
        return $this->hasManyThrough(Deployment::class, Project::class);
    }

    /** @return HasOneThrough<Deployment, Project, $this> */
    public function firstDeployment(): HasOneThrough
    {
        return $this->hasOneThrough(Deployment::class, Project::class);
    }
}
