<?php

namespace App\Services\Assessments;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * يتحقق قبل توليد أي أسئلة أن قاعدة البيانات تحوي أعمدة تقييم المستويات والمهارات المتعددة
 * (migrations 000017 و 000019). النشر لا يشغّل migrations تلقائيًا، فبدون هذا الفحص يُستهلك طلب Gemini
 * ثم يفشل الحفظ بخطأ 500، أو يفشل حفظ إجابة المقال في منتصف الاختبار.
 */
class AssessmentSchema
{
    private const CACHE_KEY = 'assessments:schema-ready:v3';

    /** @var array<string, array<int, string>> الجدول => الأعمدة المطلوبة */
    private const REQUIRED = [
        'assessment_sessions' => ['skill_id', 'requested_level', 'skill_levels'],
        'skill_assessments' => ['level'],
        'assessment_session_answers' => ['skill_id'],
    ];

    /** @var array<string, array<int, string>> الجدول => الأعمدة التي يجب أن تقبل NULL */
    private const NULLABLE = [
        // سؤال المقال يقيّم عدة مهارات فيُحفظ بلا مهارة واحدة
        'assessment_session_answers' => ['skill_id'],
    ];

    /** @return array<int, string> الأعمدة الناقصة أو غير المطابقة بصيغة table.column؛ فارغة إن كان المخطط جاهزًا */
    public static function problems(): array
    {
        if (Cache::get(self::CACHE_KEY) === true) {
            return [];
        }

        $problems = [];
        foreach (self::REQUIRED as $table => $required) {
            $columns = collect(Schema::getColumns($table))->keyBy('name');
            foreach ($required as $column) {
                if (! $columns->has($column)) {
                    $problems[] = "{$table}.{$column}";
                } elseif (in_array($column, self::NULLABLE[$table] ?? [], true) && ! $columns->get($column)['nullable']) {
                    $problems[] = "{$table}.{$column} (not nullable)";
                }
            }
        }

        // النتيجة الجاهزة فقط تُحفظ مؤقتًا: بعد تشغيل migrations يعمل التقييم مباشرة دون انتظار
        if ($problems === []) {
            Cache::put(self::CACHE_KEY, true, now()->addMinutes(10));
        }

        return $problems;
    }
}
