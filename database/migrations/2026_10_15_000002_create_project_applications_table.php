<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * طلب انضمام الطالب لمشروع. طلب واحد لكل طالب في كل مشروع (قيد فريد)، حتى بعد السحب.
     * الدور المفضل اختياري ويُحذف أثره إذا حُذف الدور؛ الدور الفعلي يُحدد عند تكوين الفريق.
     */
    public function up(): void
    {
        Schema::create('project_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_opportunity_id')->constrained('project_opportunities')->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained('student_profiles')->cascadeOnDelete();
            $table->foreignId('preferred_role_id')->nullable()->constrained('project_roles')->nullOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->text('note')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();
            $table->unique(['project_opportunity_id', 'student_profile_id'], 'project_applications_project_student_unique');
            $table->index(['student_profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_applications');
    }
};
