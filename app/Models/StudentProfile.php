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
        'graduation_year',
        'phone',
        'linkedin_url',
        'github_url',
        'image_path',
        'onboarding_completed',
    ];
    protected $casts = [
        'onboarding_completed' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function studentSkills(): HasMany
    {
        return $this->hasMany(StudentSkill::class);
    }

    public function profileDetails(): HasOne
    {
        return $this->hasOne(StudentProfileDetails::class);
    }
}
