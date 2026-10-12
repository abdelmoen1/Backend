<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectRole extends Model
{
    protected $fillable = [
        'proposal_version_id',
        'category',
        'title',
        'responsibilities',
        'rationale',
        'headcount',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'headcount' => 'integer',
            'position' => 'integer',
        ];
    }

    public function proposalVersion(): BelongsTo
    {
        return $this->belongsTo(ProjectProposalVersion::class, 'proposal_version_id');
    }

    public function skills(): HasMany
    {
        return $this->hasMany(ProjectRoleSkill::class);
    }
}
