<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * خيار إجابة. is_correct مخفي من التحويل إلى JSON حتى لا يصل مفتاح الإجابة إلى المتصفح بالخطأ.
 *
 * قديم (legacy): اختبارات الاختيار من متعدد استُبدلت بجلسات التقييم المكتوب (AssessmentSession).
 * الجدول والنموذج باقيان للحفاظ على أي نتائج سابقة مرتبطة به؛ لا يوجد مسار يكتب فيه الآن.
 */
class SkillTestOption extends Model
{
    protected $fillable = [
        'skill_test_question_id',
        'key',
        'text',
        'is_correct',
        'position',
    ];

    protected $hidden = ['is_correct'];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(SkillTestQuestion::class, 'skill_test_question_id');
    }
}
