<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectPlanComment extends Model
{
    protected $fillable = ['project_plan_version_id', 'user_id', 'author_role', 'body'];

    public function version(): BelongsTo
    {
        return $this->belongsTo(ProjectPlanVersion::class, 'project_plan_version_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
