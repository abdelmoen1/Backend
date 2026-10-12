<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * مهارات كل تخصص: هي وحدها ما يختار منه الطالب مهاراته عند إكمال ملفه.
     */
    public function up(): void
    {
        Schema::create('skill_specialization', function (Blueprint $table) {
            $table->id();
            $table->foreignId('specialization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unique(['specialization_id', 'skill_id'], 'skill_specialization_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_specialization');
    }
};
