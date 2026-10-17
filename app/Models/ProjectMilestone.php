<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** مرحلة تنفيذ منسوخة من الخطة المتفق عليها عند بدء التنفيذ؛ القائد يحدّث حالتها (FR-29). */
class ProjectMilestone extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED];

    protected $fillable = [
        'project_opportunity_id',
        'project_plan_version_id',
        'position',
        'title',
        'description',
        'due_date',
        'status',
        'completed_at',
        'completed_by_user_id',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'project_opportunity_id' => 'integer',
            'position' => 'integer',
            'due_date' => 'date:Y-m-d',
            'completed_at' => 'datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(ProjectOpportunity::class, 'project_opportunity_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class, 'project_milestone_id');
    }
}
