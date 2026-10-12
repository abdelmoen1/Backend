<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** مهارات كل دور مرتبطة بجدول skills الموجود (أساس المطابقة). */
    public function up(): void
    {
        Schema::create('project_role_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_role_id')
                ->constrained('project_roles')
                ->cascadeOnDelete();
            $table->foreignId('skill_id')
                ->constrained('skills')
                ->restrictOnDelete();
            $table->string('importance', 20);
            $table->unique(['project_role_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_role_skills');
    }
};
