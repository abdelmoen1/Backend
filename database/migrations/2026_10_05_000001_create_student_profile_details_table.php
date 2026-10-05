<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profile_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')
                ->unique()
                ->constrained('student_profiles')
                ->cascadeOnDelete();
            $table->json('portfolio')->nullable();
            $table->json('external_links')->nullable();
            $table->json('certifications')->nullable();
            $table->json('testimonials')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_profile_details');
    }
};
