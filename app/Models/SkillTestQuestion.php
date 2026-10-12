<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * قديم (legacy): اختبارات الاختيار من متعدد استُبدلت بجلسات التقييم المكتوب (AssessmentSession).
 * الجدول والنموذج باقيان للحفاظ على أي نتائج سابقة مرتبطة به؛ لا يوجد مسار يكتب فيه الآن.
 */
class SkillTestQuestion extends Model
{
    protected $fillable = [
        'skill_test_id',
        'key',
        'prompt',
        'code',
        'position',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(SkillTest::class, 'skill_test_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(SkillTestOption::class)->orderBy('position');
    }
}
