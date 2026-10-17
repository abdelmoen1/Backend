<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** تقييم قائد الفريق لأداء عضو (FR-30). واحد لكل عضو، ولا يُعدل بعد تسجيله. لا تراه المؤسسة. */
class ProjectMemberEvaluation extends Model
{
    public const CRITERIA = ['technical_quality', 'communication', 'reliability', 'teamwork'];

    protected $fillable = [
        'project_opportunity_id',
        'project_team_member_id',
        'evaluator_user_id',
        'overall_score',
        'criteria',
        'comments',
    ];

    protected function casts(): array
    {
        return [
            'project_opportunity_id' => 'integer',
            'project_team_member_id' => 'integer',
            'overall_score' => 'integer',
            'criteria' => 'array',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProjectTeamMember::class, 'project_team_member_id');
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_user_id');
    }
}
