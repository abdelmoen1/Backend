<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * دليل خبرة موثق من مشروع حقيقي أُكمل (FR-09, FR-32): الدور، المهارات المستخدمة، المهام المنجزة والتقييمات.
 * يُنشأ فقط لمن شارك فعلًا في المشروع (عضو في الفريق أو قائده) عند إكمال المشروع.
 */
class StudentEvidence extends Model
{
    public const PARTICIPATION_MEMBER = 'member';

    public const PARTICIPATION_LEADER = 'leader';

    protected $table = 'student_evidence';

    protected $fillable = [
        'student_profile_id',
        'project_opportunity_id',
        'project_team_member_id',
        'participation',
        'role_title',
        'summary',
        'skills_used',
        'completed_tasks',
        'completed_tasks_count',
        'member_evaluation_id',
        'client_satisfaction_score',
        'final_result',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'student_profile_id' => 'integer',
            'project_opportunity_id' => 'integer',
            'project_team_member_id' => 'integer',
            'member_evaluation_id' => 'integer',
            'skills_used' => 'array',
            'completed_tasks' => 'array',
            'completed_tasks_count' => 'integer',
            'client_satisfaction_score' => 'integer',
            'issued_at' => 'datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(ProjectOpportunity::class, 'project_opportunity_id');
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(ProjectMemberEvaluation::class, 'member_evaluation_id');
    }
}
