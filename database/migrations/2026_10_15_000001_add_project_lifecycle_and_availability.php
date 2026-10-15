<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * مرحلة المشروع (open → matching → team_formation ...) منفصلة عن حالة النشر (published/suspended/withdrawn):
     * النشر يحدد الظهور للطلاب، والمرحلة تحدد هل يقبل المشروع طلبات الانضمام. المشاريع المنشورة سابقًا تبدأ open.
     * ساعات التفرغ الأسبوعية للطالب والحد الأدنى للمشروع هما مدخل "التوفر" في المطابقة؛ NULL = غير محدد.
     */
    public function up(): void
    {
        Schema::table('project_opportunities', function (Blueprint $table) {
            $table->string('project_status', 20)->default('open')->after('status')->index();
            $table->unsignedTinyInteger('min_weekly_hours')->nullable()->after('summary');
        });

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->unsignedTinyInteger('weekly_availability_hours')->nullable()->after('professional_specialization');
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn('weekly_availability_hours');
        });

        Schema::table('project_opportunities', function (Blueprint $table) {
            $table->dropIndex(['project_status']);
            $table->dropColumn(['project_status', 'min_weekly_hours']);
        });
    }
};
