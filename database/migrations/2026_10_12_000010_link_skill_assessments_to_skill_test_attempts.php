<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * يبقى skill_assessments سجل النتائج الوحيد الذي تعتمد عليه المطابقة. نتيجة اختبار المنصة
     * ترتبط بمحاولتها، والفهرس الفريد يضمن نتيجة واحدة فقط لكل محاولة حتى مع إعادة الإرسال.
     * نتائج الإدارة (skills:record-assessment) تبقى بلا محاولة.
     */
    public function up(): void
    {
        Schema::table('skill_assessments', function (Blueprint $table) {
            $table->foreignId('skill_test_attempt_id')
                ->nullable()
                ->after('skill_id')
                ->unique()
                ->constrained('skill_test_attempts')
                // الطالب المحذوف تُحذف محاولاته ونتائجه معًا؛ RESTRICT هنا قد يمنع الحذف حسب ترتيب التتابع
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('skill_assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('skill_test_attempt_id');
        });
    }
};
