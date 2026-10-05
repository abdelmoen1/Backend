<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_session_id')
                ->constrained('intake_sessions')
                ->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('question_id');
            $table->text('question_text');
            $table->string('question_type');
            $table->json('options')->nullable();
            $table->json('selected_answer')->nullable();
            $table->text('custom_answer')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['intake_session_id', 'sequence']);
            $table->index(['intake_session_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_responses');
    }
};
