<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * نسخة من خطة تنفيذ المشروع. draft فقط قابلة للتعديل (القائد)؛ submitted مجمدة ببصمة محتواها وتمثل موافقة القائد
 * على هذه النسخة بعينها؛ approved = وافقت المؤسسة على النسخة والبصمة نفسيهما فصارت خط الأساس.
 */
class ProjectPlanVersion extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_CHANGES_REQUESTED = 'changes_requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'project_opportunity_id',
        'version',
        'status',
        'content',
        'content_hash',
        'created_by_user_id',
        'submitted_by_user_id',
        'submitted_at',
        'organization_decided_by_user_id',
        'organization_decision',
        'organization_decided_at',
        'organization_note',
    ];

    protected function casts(): array
    {
        return [
            'project_opportunity_id' => 'integer',
            'version' => 'integer',
            'content' => 'array',
            'submitted_at' => 'datetime',
            'organization_decided_at' => 'datetime',
        ];
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(ProjectOpportunity::class, 'project_opportunity_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ProjectPlanComment::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organization_decided_by_user_id');
    }
}
