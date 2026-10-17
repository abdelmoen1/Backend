<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مرحلة التنفيذ (FR-25 حتى FR-32): مراحل المشروع المنسوخة من الخطة المتفق عليها، المهام، التسليمات ومراجعتها،
 * تقييم القائد للأعضاء، تقييم المؤسسة النهائي، وسجل الأدلة (Evidence) الذي يُبنى من العمل المنجز فعلًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_opportunity_id')->constrained('project_opportunities')->cascadeOnDelete();
            $table->foreignId('project_plan_version_id')->nullable()->constrained('project_plan_versions')->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->date('due_date');
            $table->string('status', 20)->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_opportunity_id', 'position'], 'project_milestones_project_position_unique');
        });

        Schema::create('project_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_opportunity_id')->constrained('project_opportunities')->cascadeOnDelete();
            $table->foreignId('project_team_id')->constrained('project_teams')->cascadeOnDelete();
            $table->foreignId('project_milestone_id')->nullable()->constrained('project_milestones')->nullOnDelete();
            $table->foreignId('assignee_member_id')->nullable()->constrained('project_team_members')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('priority', 10)->default('medium');
            $table->string('status', 20)->default('todo');
            $table->date('due_date')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['project_opportunity_id', 'status'], 'project_tasks_project_status_index');
        });

        Schema::create('project_deliverables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_task_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->foreignId('project_team_member_id')->constrained('project_team_members')->cascadeOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('revision');
            $table->string('reference_url', 2048)->nullable();
            $table->text('notes');
            $table->string('status', 20)->default('pending_review');
            $table->text('feedback')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['project_task_id', 'revision'], 'project_deliverables_task_revision_unique');
        });

        // تقييم واحد من القائد لكل عضو في المشروع
        Schema::create('project_member_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_opportunity_id')->constrained('project_opportunities')->cascadeOnDelete();
            $table->foreignId('project_team_member_id')->constrained('project_team_members')->cascadeOnDelete();
            $table->foreignId('evaluator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('overall_score');
            $table->json('criteria');
            $table->text('comments');
            $table->timestamps();
            $table->unique('project_team_member_id', 'project_member_evaluations_member_unique');
        });

        // تقييم المؤسسة النهائي للمشروع (FR-31)، واحد لكل مشروع
        Schema::create('project_client_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_opportunity_id')->constrained('project_opportunities')->cascadeOnDelete();
            $table->foreignId('organization_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('satisfaction_score');
            $table->unsignedTinyInteger('leader_score')->nullable();
            $table->boolean('would_recommend');
            $table->text('comments');
            $table->timestamps();
            $table->unique('project_opportunity_id', 'project_client_feedback_project_unique');
        });

        Schema::create('student_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained('student_profiles')->cascadeOnDelete();
            $table->foreignId('project_opportunity_id')->constrained('project_opportunities')->cascadeOnDelete();
            $table->foreignId('project_team_member_id')->nullable()->constrained('project_team_members')->nullOnDelete();
            $table->string('participation', 20);
            $table->string('role_title', 200)->nullable();
            $table->text('summary');
            $table->json('skills_used');
            $table->json('completed_tasks');
            $table->unsignedInteger('completed_tasks_count')->default(0);
            $table->foreignId('member_evaluation_id')->nullable()->constrained('project_member_evaluations')->nullOnDelete();
            $table->unsignedTinyInteger('client_satisfaction_score')->nullable();
            $table->string('final_result', 20);
            $table->timestamp('issued_at');
            $table->timestamps();
            $table->unique(['student_profile_id', 'project_opportunity_id'], 'student_evidence_student_project_unique');
        });

        Schema::table('project_opportunities', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('execution_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('project_opportunities', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
        Schema::dropIfExists('student_evidence');
        Schema::dropIfExists('project_client_feedback');
        Schema::dropIfExists('project_member_evaluations');
        Schema::dropIfExists('project_deliverables');
        Schema::dropIfExists('project_tasks');
        Schema::dropIfExists('project_milestones');
    }
};
