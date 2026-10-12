<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** الفرصة المنشورة للطلاب: واحدة فقط لكل طلب مشروع، وتشير إلى النسخة المعتمدة النشطة. */
    public function up(): void
    {
        Schema::create('project_opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_request_id')
                ->unique()
                ->constrained('project_requests')
                ->cascadeOnDelete();
            $table->foreignId('organization_id')
                ->constrained('organization_profiles')
                ->cascadeOnDelete();
            $table->foreignId('active_version_id')
                ->constrained('project_proposal_versions')
                ->restrictOnDelete();
            $table->string('status', 20)->index();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->text('moderation_note')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_opportunities');
    }
};
