<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** نسخة معتمدة ومجمدة من المقترح ومتطلبات الفريق؛ تُنشأ مرة واحدة ولا تُعدَّل. */
class ProjectProposalVersion extends Model
{
    protected $fillable = [
        'project_request_id',
        'version',
        'proposal',
        'staffing',
        'proposal_hash',
        'confirmation_hash',
        'approved_by_user_id',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'proposal' => 'array',
            'staffing' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function projectRequest(): BelongsTo
    {
        return $this->belongsTo(ProjectRequest::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(ProjectRole::class, 'proposal_version_id')->orderBy('position');
    }
}
