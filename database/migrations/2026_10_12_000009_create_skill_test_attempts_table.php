<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * محاولات الطلاب. questions نسخة مجمدة من الأسئلة المعروضة مع مفتاح الإجابة (لا تُرسل للمتصفح)،
     * و pass_percentage قاعدة النجاح وقت البدء؛ فيبقى التصحيح والسجل صحيحين حتى لو عُدّل البنك لاحقًا.
     *
     * open_slot = 1 للمحاولة الجارية و NULL بعد إغلاقها؛ الفهرس الفريد عليه يمنع على مستوى قاعدة
     * البيانات وجود محاولتين مفتوحتين لنفس الطالب ونفس الاختبار (القيم NULL لا تتعارض في MySQL/SQLite).
     * النتيجة (ناجح/راسب والدرجة) لا تُخزن هنا بل في skill_assessments المرتبط بالمحاولة.
     */
    public function up(): void
    {
        Schema::create('skill_test_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')
                ->constrained('student_profiles')
                ->cascadeOnDelete();
            $table->foreignId('skill_test_id')
                ->constrained('skill_tests')
                ->restrictOnDelete();
            $table->unsignedInteger('content_version');
            $table->string('status', 20);
            $table->unsignedTinyInteger('open_slot')->nullable();
            $table->json('questions');
            $table->json('answers')->nullable();
            $table->unsignedSmallInteger('question_count');
            $table->unsignedTinyInteger('pass_percentage');
            $table->unsignedSmallInteger('correct_count')->nullable();
            // dateTime وليس timestamp: في MariaDB/MySQL مع explicit_defaults_for_timestamp=OFF يأخذ أول
            // عمود timestamp غير فارغ ON UPDATE CURRENT_TIMESTAMP (فيتغير وقت البدء عند التسليم) ويُرفض الثاني
            $table->dateTime('started_at');
            $table->dateTime('expires_at');
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['student_profile_id', 'skill_test_id', 'open_slot'], 'skill_test_attempts_one_open_unique');
            $table->index(['student_profile_id', 'skill_test_id', 'status'], 'skill_test_attempts_student_test_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_test_attempts');
    }
};
