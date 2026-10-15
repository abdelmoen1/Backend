<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectTeamMember extends Model
{
    protected $fillable = [
        'project_team_id',
        'student_profile_id',
        'project_role_id',
        'project_application_id',
    ];

    protected function casts(): array
    {
        return [
            'project_team_id' => 'integer',
            'student_profile_id' => 'integer',
            'project_role_id' => 'integer',
            'project_application_id' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(ProjectTeam::class, 'project_team_id');
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(ProjectRole::class, 'project_role_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ProjectApplication::class, 'project_application_id');
    }
}
