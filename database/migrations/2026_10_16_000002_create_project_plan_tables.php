<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مساحة التخطيط والاتفاق بين قائد الفريق والمؤسسة: نسخ خطة مرقمة (المسودة وحدها قابلة للتعديل؛ المقدمة
 * مجمدة ببصمة محتواها)، تعليقات، والنسخة المتفق عليها التي تصبح خط الأساس للتنفيذ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_plan_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_opportunity_id')->constrained('project_opportunities')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->index();
            $table->json('content');
            $table->char('content_hash', 64)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('organization_decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('organization_decision', 20)->nullable();
            $table->timestamp('organization_decided_at')->nullable();
            $table->text('organization_note')->nullable();
            $table->timestamps();
            $table->unique(['project_opportunity_id', 'version'], 'project_plan_versions_project_version_unique');
        });

        Schema::create('project_plan_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_plan_version_id')->constrained('project_plan_versions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_role', 20);
            $table->text('body');
            $table->timestamps();
        });

        Schema::table('project_opportunities', function (Blueprint $table) {
            $table->foreignId('agreed_plan_version_id')->nullable()->after('active_version_id')
                ->constrained('project_plan_versions')->nullOnDelete();
            $table->timestamp('agreed_at')->nullable()->after('agreed_plan_version_id');
        });
    }

    public function down(): void
    {
        Schema::table('project_opportunities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agreed_plan_version_id');
            $table->dropColumn('agreed_at');
        });
        Schema::dropIfExists('project_plan_comments');
        Schema::dropIfExists('project_plan_versions');
    }
};
