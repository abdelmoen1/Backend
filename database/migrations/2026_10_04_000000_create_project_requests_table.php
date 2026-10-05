<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organization_profiles')
                ->cascadeOnDelete();
            $table->string('title');
            $table->text('problem_description')->nullable();
            $table->json('current_process')->nullable();
            $table->json('target_users')->nullable();
            $table->json('desired_outcomes')->nullable();
            $table->json('existing_tools')->nullable();
            $table->date('deadline')->nullable();
            $table->string('priority')->nullable();
            $table->string('budget_range')->nullable();
            $table->text('special_requirements')->nullable();
            $table->string('status')->default('draft')->index();
            $table->json('intake_data')->nullable();
            $table->json('proposal')->nullable();
            $table->text('revision_note')->nullable();
            $table->timestamp('proposal_generated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_requests');
    }
};
