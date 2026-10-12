<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * جلسة تقييم مفتوحة الإجابة يولّد Gemini أسئلتها. questions نسخة مجمدة من الأسئلة مع معايير التصحيح
     * الخاصة (rubric) ولا تُرسل للمتصفح؛ settings قواعد النجاح وقت الإنشاء. لا تُنشأ الجلسة إلا بعد
     * التحقق من صحة الأسئلة المولدة، فلا توجد جلسة "مكتملة" ظاهريًا دون أسئلة صالحة.
     *
     * open_slot = 1 للجلسة غير المكتملة (قيد الإجابة أو التصحيح) و NULL بعد اكتمالها؛ الفهرس الفريد يمنع
     * جلستين مفتوحتين للطالب نفسه.
     */
    public function up(): void
    {
        Schema::create('assessment_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')
                ->constrained('student_profiles')
                ->cascadeOnDelete();
            $table->string('professional_specialization', 40);
            $table->string('status', 20);
            $table->unsignedTinyInteger('open_slot')->nullable();
            $table->string('title');
            $table->json('skill_ids');
            $table->json('questions');
            $table->unsignedSmallInteger('question_count');
            $table->json('settings');
            $table->json('generation');
            // dateTime وليس timestamp لتجنب القيم الافتراضية الضمنية في MySQL/MariaDB
            $table->dateTime('started_at');
            $table->dateTime('expires_at');
            $table->dateTime('answering_ended_at')->nullable();
            $table->boolean('ended_by_timeout')->default(false);
            $table->dateTime('completed_at')->nullable();
            $table->string('last_grading_error', 40)->nullable();
            $table->timestamps();
            $table->unique(['student_profile_id', 'open_slot'], 'assessment_sessions_one_open_unique');
            $table->index(['student_profile_id', 'status'], 'assessment_sessions_student_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_sessions');
    }
};
