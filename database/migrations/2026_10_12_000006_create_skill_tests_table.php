<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تعريف اختبار المهارة داخل المنصة: اختبار واحد لكل مهارة، بقاعدة نجاح وسياسة إعادة صريحتين.
     * المحتوى يُكتب في database/assessments/*.json ويُزامن بالأمر assessments:sync.
     */
    public function up(): void
    {
        Schema::create('skill_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skill_id')
                ->unique()
                ->constrained('skills')
                ->restrictOnDelete();
            $table->string('slug', 60)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('pass_percentage');
            $table->unsignedSmallInteger('questions_per_attempt');
            $table->unsignedSmallInteger('time_limit_minutes');
            $table->unsignedSmallInteger('retake_after_hours');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('content_version')->default(1);
            $table->char('content_hash', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_tests');
    }
};
