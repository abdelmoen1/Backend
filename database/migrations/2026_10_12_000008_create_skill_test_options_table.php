<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** خيارات السؤال. is_correct هو مفتاح الإجابة ولا يُرسل إلى المتصفح أبدًا. */
    public function up(): void
    {
        Schema::create('skill_test_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skill_test_question_id')
                ->constrained('skill_test_questions')
                ->cascadeOnDelete();
            $table->string('key', 10);
            $table->text('text');
            $table->boolean('is_correct')->default(false);
            $table->unsignedTinyInteger('position');
            $table->timestamps();
            $table->unique(['skill_test_question_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_test_options');
    }
};
