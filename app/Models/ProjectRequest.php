<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProjectRequest extends Model
{
    protected $table = 'project_requests';

    protected $fillable = [
        'organization_id',
        'title',
        'problem_description',
        'current_process',
        'target_users',
        'desired_outcomes',
        'existing_tools',
        'deadline',
        'priority',
        'budget_range',
        'special_requirements',
        'status',
        'intake_data',
        'proposal',
        'revision_note',
        'proposal_generated_at',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'current_process' => 'array',
            'target_users' => 'array',
            'desired_outcomes' => 'array',
            'existing_tools' => 'array',
            'intake_data' => 'array',
            'proposal' => 'array',
            'deadline' => 'date',
            'proposal_generated_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(OrganizationProfile::class, 'organization_id');
    }

    public function intakeSession(): HasOne
    {
        return $this->hasOne(IntakeSession::class);
    }
}
