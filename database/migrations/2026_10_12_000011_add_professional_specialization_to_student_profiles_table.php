<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * المسار المهني للطالب (مفتاح من جدول specializations) وهو ما تعتمد عليه مطابقة أدوار المشاريع.
     * يبقى academic_major كما هو: التخصص الجامعي بيانات أكاديمية منفصلة ولا يُحذف.
     */
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->string('professional_specialization', 40)->nullable()->after('academic_major');
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn('professional_specialization');
        });
    }
};
