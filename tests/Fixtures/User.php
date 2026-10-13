<?php declare(strict_types=1);

namespace BackofficeMcp\Tests\Fixtures;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class User extends Model
{
    protected $guarded = [];

    // Tuned for another audience: the whitelist alone decides what the MCP client sees
    protected $hidden = ['email'];
    protected $appends = ['display_name'];

    /** @return BelongsToMany<Team, $this> */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class);
    }

    /** @return Attribute<string, never> */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn() => strtoupper((string) $this->name));
    }
}
