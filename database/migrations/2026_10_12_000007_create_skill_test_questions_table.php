<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** أسئلة الاختبار. key ثابت داخل الاختبار حتى تحدّث المزامنة السؤال نفسه بدل إنشاء نسخة. */
    public function up(): void
    {
        Schema::create('skill_test_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skill_test_id')
                ->constrained('skill_tests')
                ->cascadeOnDelete();
            $table->string('key', 60);
            $table->text('prompt');
            $table->text('code')->nullable();
            $table->unsignedSmallInteger('position');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['skill_test_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_test_questions');
    }
};
