<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * التخصصات التقنية التي يختار الطالب منها (تملؤها SpecializationSeeder).
     * key هو ما يُحفظ في student_profiles.professional_specialization، و role_categories فئات أدوار
     * المشاريع المتوافقة مع التخصص (ProjectStaffing::ROLE_CATEGORIES).
     */
    public function up(): void
    {
        Schema::create('specializations', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name');
            $table->string('title', 60);
            $table->string('description')->nullable();
            $table->json('role_categories');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specializations');
    }
};
