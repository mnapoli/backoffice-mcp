<?php declare(strict_types=1);

namespace BackofficeMcp\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deployment extends Model
{
    protected $guarded = [];

    // Loaded with every deployment by the application, whatever the MCP client asked for
    protected $with = ['project'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
