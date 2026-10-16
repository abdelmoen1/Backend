<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تعيين قائد فريق لمشروع بعينه بقرار من مدير المنصة. السجلات لا تُحذف: الاستبدال أو الإلغاء يملأ revoked_*
 * ويفرغ active_project_opportunity_id (قيد فريد = قائد سارٍ واحد لكل مشروع).
 */
class ProjectLeaderAssignment extends Model
{
    protected $fillable = [
        'project_opportunity_id',
        'leader_user_id',
        'active_project_opportunity_id',
        'assigned_by_user_id',
        'assigned_at',
        'eligibility',
        'override_justification',
        'revoked_by_user_id',
        'revoked_at',
        'revocation_reason',
    ];

    protected function casts(): array
    {
        return [
            'project_opportunity_id' => 'integer',
            'leader_user_id' => 'integer',
            'active_project_opportunity_id' => 'integer',
            'eligibility' => 'array',
            'assigned_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('active_project_opportunity_id');
    }

    public function isActive(): bool
    {
        return $this->active_project_opportunity_id !== null;
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(ProjectOpportunity::class, 'project_opportunity_id');
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }
}
