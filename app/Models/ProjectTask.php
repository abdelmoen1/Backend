<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مهمة تنفيذية داخل المشروع (FR-26). تُسند لعضو في فريق المشروع فقط.
 * in_review و changes_requested تضبطهما دورة التسليم والمراجعة، لا التعديل اليدوي.
 */
class ProjectTask extends Model
{
    public const STATUS_TODO = 'todo';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_CHANGES_REQUESTED = 'changes_requested';

    public const STATUS_DONE = 'done';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_TODO,
        self::STATUS_IN_PROGRESS,
        self::STATUS_IN_REVIEW,
        self::STATUS_CHANGES_REQUESTED,
        self::STATUS_DONE,
        self::STATUS_CANCELLED,
    ];

    /** الحالات التي يضبطها القائد يدويًا */
    public const MANUAL_STATUSES = [self::STATUS_TODO, self::STATUS_IN_PROGRESS, self::STATUS_DONE, self::STATUS_CANCELLED];

    /** مهمة تقبل تسليمًا من العضو المسند إليه */
    public const SUBMITTABLE_STATUSES = [self::STATUS_TODO, self::STATUS_IN_PROGRESS, self::STATUS_CHANGES_REQUESTED];

    /** مهام لم تُغلق بعد وتمنع إكمال المشروع */
    public const OPEN_STATUSES = [self::STATUS_TODO, self::STATUS_IN_PROGRESS, self::STATUS_IN_REVIEW, self::STATUS_CHANGES_REQUESTED];

    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    protected $fillable = [
        'project_opportunity_id',
        'project_team_id',
        'project_milestone_id',
        'assignee_member_id',
        'title',
        'description',
        'priority',
        'status',
        'due_date',
        'created_by_user_id',
        'completed_at',
    ];

    protected $attributes = [
        'priority' => 'medium',
        'status' => self::STATUS_TODO,
    ];

    protected function casts(): array
    {
        return [
            'project_opportunity_id' => 'integer',
            'project_team_id' => 'integer',
            'project_milestone_id' => 'integer',
            'assignee_member_id' => 'integer',
            'due_date' => 'date:Y-m-d',
            'completed_at' => 'datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(ProjectOpportunity::class, 'project_opportunity_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class, 'project_milestone_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(ProjectTeamMember::class, 'assignee_member_id');
    }

    public function deliverables(): HasMany
    {
        return $this->hasMany(ProjectDeliverable::class);
    }
}
