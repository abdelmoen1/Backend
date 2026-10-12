<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** أدوار الفريق المعتمدة ضمن نسخة مقترح محددة. */
    public function up(): void
    {
        Schema::create('project_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_version_id')
                ->constrained('project_proposal_versions')
                ->cascadeOnDelete();
            $table->string('category', 40);
            $table->string('title');
            $table->text('responsibilities');
            $table->text('rationale')->nullable();
            $table->unsignedTinyInteger('headcount')->default(1);
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();
            $table->index(['proposal_version_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_roles');
    }
};
