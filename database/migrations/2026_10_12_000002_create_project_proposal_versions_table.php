<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** نسخة مقترح معتمدة ومجمدة؛ لا تُعدَّل بعد إنشائها. */
    public function up(): void
    {
        Schema::create('project_proposal_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_request_id')
                ->constrained('project_requests')
                ->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('proposal');
            $table->json('staffing');
            $table->char('proposal_hash', 64);
            $table->foreignId('approved_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->dateTime('approved_at');
            $table->timestamps();
            // أسماء صريحة: الاسم التلقائي للفهرس الثاني يتجاوز حد MySQL البالغ 64 حرفًا
            $table->unique(['project_request_id', 'version'], 'proposal_versions_request_version_unique');
            // إعادة اعتماد نفس المحتوى لا تنشئ نسخة جديدة
            $table->unique(['project_request_id', 'proposal_hash'], 'proposal_versions_request_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_proposal_versions');
    }
};
