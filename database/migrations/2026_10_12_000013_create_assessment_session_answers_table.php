<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * إجابة واحدة لكل سؤال في الجلسة، محفوظة نصًا كما أرسلها الطالب (دون قص المسافات) قبل أي تصحيح.
     * grade نتيجة تصحيح Gemini بعد التحقق منها في الخادم؛ grading_failures عدد محاولات التصحيح الفاشلة.
     */
    public function up(): void
    {
        Schema::create('assessment_session_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_session_id')
                ->constrained('assessment_sessions')
                ->cascadeOnDelete();
            $table->string('question_id', 20);
            $table->unsignedSmallInteger('position');
            $table->foreignId('skill_id')
                ->constrained('skills')
                ->restrictOnDelete();
            $table->longText('answer');
            $table->dateTime('submitted_at');
            $table->string('grade_status', 20);
            $table->unsignedTinyInteger('grading_failures')->default(0);
            $table->json('grade')->nullable();
            $table->unsignedSmallInteger('awarded_points')->nullable();
            $table->unsignedSmallInteger('max_points');
            $table->dateTime('graded_at')->nullable();
            $table->timestamps();
            $table->unique(['assessment_session_id', 'question_id'], 'session_answers_question_unique');
            $table->unique(['assessment_session_id', 'position'], 'session_answers_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_session_answers');
    }
};
