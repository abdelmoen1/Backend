<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class OrganizationProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'organization_name',
        'organization_type',
        'industry',
        'description',
        'location',
        'website',
        'contact_person',
        'onboarding_completed',
    ];

    protected function casts(): array
    {
        return [
            'onboarding_completed' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function projectRequests(): HasMany
    {
        return $this->hasMany(ProjectRequest::class, 'organization_id');
    }

    public function intakeSessions(): HasManyThrough
    {
        return $this->hasManyThrough(
            IntakeSession::class,
            ProjectRequest::class,
            'organization_id',
            'project_request_id'
        );
    }
}
