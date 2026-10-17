<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** تقييم المؤسسة النهائي لرضاها عن المشروع بعد إكماله (FR-31). واحد لكل مشروع. */
class ProjectClientFeedback extends Model
{
    protected $table = 'project_client_feedback';

    protected $fillable = [
        'project_opportunity_id',
        'organization_user_id',
        'satisfaction_score',
        'leader_score',
        'would_recommend',
        'comments',
    ];

    protected function casts(): array
    {
        return [
            'project_opportunity_id' => 'integer',
            'satisfaction_score' => 'integer',
            'leader_score' => 'integer',
            'would_recommend' => 'boolean',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(ProjectOpportunity::class, 'project_opportunity_id');
    }
}
