<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** تسليم عضو لمهمة مسندة إليه (FR-27) ونتيجة مراجعة القائد له (FR-28). كل إعادة تسليم نسخة جديدة مرقمة. */
class ProjectDeliverable extends Model
{
    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REVISION_REQUESTED = 'revision_requested';

    protected $fillable = [
        'project_task_id',
        'project_team_member_id',
        'submitted_by_user_id',
        'revision',
        'reference_url',
        'notes',
        'status',
        'feedback',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'project_task_id' => 'integer',
            'project_team_member_id' => 'integer',
            'revision' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'project_task_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProjectTeamMember::class, 'project_team_member_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
