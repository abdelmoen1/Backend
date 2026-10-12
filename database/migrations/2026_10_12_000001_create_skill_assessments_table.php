<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * نتائج تقييم المهارات: الدليل الوحيد على أن مهارة الطالب "مُتحقق منها".
     * مستوى الإتقان في student_skills يختاره الطالب بنفسه ولا يُعد تقييمًا.
     */
    public function up(): void
    {
        Schema::create('skill_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')
                ->constrained('student_profiles')
                ->cascadeOnDelete();
            $table->foreignId('skill_id')
                ->constrained('skills')
                ->restrictOnDelete();
            $table->string('status', 20);
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('source', 40);
            $table->foreignId('recorded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('notes')->nullable();
            $table->dateTime('assessed_at');
            $table->timestamps();
            $table->index(['student_profile_id', 'skill_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_assessments');
    }
};
