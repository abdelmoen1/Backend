<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مدير المنصة يراجع المشاريع قبل نشرها ويعيّن قائد فريق لكل مشروع (تعيين مرتبط بالمشروع لا بالحساب)،
 * مع سجل تدقيق للإجراءات الحرجة. active_project_opportunity_id يساوي معرّف المشروع للتعيين الساري و NULL بعد
 * الإلغاء، والقيد الفريد عليه يضمن قائدًا ساريًا واحدًا لكل مشروع على MySQL و PostgreSQL معًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('role');
            $table->timestamp('deactivated_at')->nullable()->after('is_active');
        });

        Schema::table('project_opportunities', function (Blueprint $table) {
            $table->foreignId('reviewed_by_user_id')->nullable()->after('moderation_note')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_user_id');
            $table->timestamp('submitted_for_review_at')->nullable()->after('reviewed_at');
            $table->timestamp('execution_started_at')->nullable()->after('submitted_for_review_at');
        });

        Schema::create('project_leader_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_opportunity_id')->constrained('project_opportunities')->cascadeOnDelete();
            $table->foreignId('leader_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('active_project_opportunity_id')->nullable()->unique('project_leader_assignments_active_unique');
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->json('eligibility')->nullable();
            $table->text('override_justification')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['leader_user_id', 'revoked_at'], 'project_leader_assignments_leader_idx');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60)->index();
            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['entity_type', 'entity_id'], 'audit_logs_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('project_leader_assignments');

        Schema::table('project_opportunities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropColumn(['reviewed_at', 'submitted_for_review_at', 'execution_started_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'deactivated_at']);
        });
    }
};
