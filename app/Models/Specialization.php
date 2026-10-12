<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * تخصص تقني يختاره الطالب (Frontend، Backend، ...). تديره SpecializationSeeder.
 */
class Specialization extends Model
{
    protected $fillable = [
        'key',
        'name',
        'title',
        'description',
        'role_categories',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'role_categories' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class)
            ->withPivot('sort_order')
            ->orderByPivot('sort_order')
            ->orderBy('skills.name');
    }
}
