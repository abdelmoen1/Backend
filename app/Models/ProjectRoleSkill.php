<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectRoleSkill extends Model
{
    public const MANDATORY = 'mandatory';

    public const DESIRABLE = 'desirable';

    public $timestamps = false;

    protected $fillable = [
        'project_role_id',
        'skill_id',
        'importance',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(ProjectRole::class, 'project_role_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
