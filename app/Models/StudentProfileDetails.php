<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentProfileDetails extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_profile_id',
        'portfolio',
        'external_links',
        'certifications',
        'testimonials',
    ];

    protected function casts(): array
    {
        return [
            'portfolio' => 'array',
            'external_links' => 'array',
            'certifications' => 'array',
            'testimonials' => 'array',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }
}
