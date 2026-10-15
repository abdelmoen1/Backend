<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StudentProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'bio',
        'status',
        'university',
        'academic_major',
        'professional_specialization',
        'weekly_availability_hours',
        'graduation_year',
        'phone',
        'linkedin_url',
        'github_url',
        'image_path',
        'onboarding_completed',
    ];

    protected $casts = [
        'onboarding_completed' => 'boolean',
        'weekly_availability_hours' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function studentSkills(): HasMany
    {
        return $this->hasMany(StudentSkill::class);
    }

    public function skillAssessments(): HasMany
    {
        return $this->hasMany(SkillAssessment::class);
    }

    public function assessmentSessions(): HasMany
    {
        return $this->hasMany(AssessmentSession::class);
    }

    public function skillTestAttempts(): HasMany
    {
        return $this->hasMany(SkillTestAttempt::class);
    }

    public function profileDetails(): HasOne
    {
        return $this->hasOne(StudentProfileDetails::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ProjectApplication::class);
    }

    public function teamMemberships(): HasMany
    {
        return $this->hasMany(ProjectTeamMember::class);
    }
}
