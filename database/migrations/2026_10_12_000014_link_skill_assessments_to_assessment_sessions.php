<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * جلسة واحدة قد تقيّم عدة مهارات؛ لكل مهارة نتيجة مستقلة في skill_assessments (الجدول الوحيد الذي
     * تقرأ منه المطابقة). الفهرس الفريد (الجلسة، المهارة) يجعل تسجيل النتائج آمنًا عند إعادة المحاولة.
     * نتائج الإدارة والنتائج القديمة تبقى بلا جلسة (NULL لا يتعارض مع الفهرس الفريد).
     */
    public function up(): void
    {
        Schema::table('skill_assessments', function (Blueprint $table) {
            $table->foreignId('assessment_session_id')
                ->nullable()
                ->after('skill_test_attempt_id')
                ->constrained('assessment_sessions')
                ->cascadeOnDelete();
            $table->unique(['assessment_session_id', 'skill_id'], 'skill_assessments_session_skill_unique');
        });
    }

    public function down(): void
    {
        Schema::table('skill_assessments', function (Blueprint $table) {
            // المفتاح الأجنبي أولًا: قد يعتمد MySQL على الفهرس الفريد لتنفيذه
            $table->dropForeign(['assessment_session_id']);
            $table->dropUnique('skill_assessments_session_skill_unique');
            $table->dropColumn('assessment_session_id');
        });
    }
};
