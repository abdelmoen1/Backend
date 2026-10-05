<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_request_id')
                ->unique()
                ->constrained('project_requests')
                ->cascadeOnDelete();
            $table->string('status')->default('active')->index();
            $table->string('current_question_id')->nullable();
            $table->json('current_state')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_sessions');
    }
};
