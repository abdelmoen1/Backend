<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * قديم (legacy): اختبارات الاختيار من متعدد استُبدلت بجلسات التقييم المكتوب (AssessmentSession).
 * الجدول والنموذج باقيان للحفاظ على أي نتائج سابقة مرتبطة به؛ لا يوجد مسار يكتب فيه الآن.
 */
class SkillTest extends Model
{
    protected $fillable = [
        'skill_id',
        'slug',
        'title',
        'description',
        'pass_percentage',
        'questions_per_attempt',
        'time_limit_minutes',
        'retake_after_hours',
        'is_active',
        'content_version',
        'content_hash',
    ];

    protected function casts(): array
    {
        return [
            'pass_percentage' => 'integer',
            'questions_per_attempt' => 'integer',
            'time_limit_minutes' => 'integer',
            'retake_after_hours' => 'integer',
            'is_active' => 'boolean',
            'content_version' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(SkillTestQuestion::class);
    }

    public function activeQuestions(): HasMany
    {
        return $this->questions()->where('is_active', true);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(SkillTestAttempt::class);
    }
}
