<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * فريق المشروع وأعضاؤه. القائد عمود واحد في الفريق، فلا يمكن وجود أكثر من قائد؛ عضويته تُتحقق في الخادم.
     * العضو مرتبط بالدور الذي سيشغله وبطلب الانضمام الذي اختير منه، ولا يتكرر الطالب في الفريق نفسه.
     */
    public function up(): void
    {
        Schema::create('project_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_opportunity_id')->constrained('project_opportunities')->cascadeOnDelete();
            $table->string('status', 20)->default('forming')->index();
            $table->foreignId('leader_student_profile_id')->nullable()->constrained('student_profiles')->nullOnDelete();
            $table->timestamp('leader_assigned_at')->nullable();
            $table->foreignId('leader_assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();
            $table->index(['project_opportunity_id', 'status']);
        });

        Schema::create('project_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_team_id')->constrained('project_teams')->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained('student_profiles')->cascadeOnDelete();
            $table->foreignId('project_role_id')->constrained('project_roles')->cascadeOnDelete();
            $table->foreignId('project_application_id')->nullable()->constrained('project_applications')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_team_id', 'student_profile_id'], 'project_team_members_team_student_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_team_members');
        Schema::dropIfExists('project_teams');
    }
};
