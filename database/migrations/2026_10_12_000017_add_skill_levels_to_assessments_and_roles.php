<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * مستويات الإتقان (مبتدئ/متوسط/متقدم):
     * - assessment_sessions.skill_id و requested_level: كل محاولة لمهارة واحدة بمستوى يطلبه الطالب (طلب صعوبة، لا إثبات).
     * - skill_assessments.level: المستوى الذي تخصه النتيجة؛ لا يُثبت إلا مع status = passed.
     * - project_role_skills.min_level: الحد الأدنى الذي أكدته المؤسسة لمهارة إلزامية.
     * - project_proposal_versions.confirmation_hash: هوية النسخة المعتمدة = المقترح + المستويات المؤكدة، فتغيير
     *   المستويات وحدها ينشئ نسخة جديدة بدل تعديل نسخة معتمدة.
     *
     * قواعد البيانات السابقة (صريحة وموثقة في التقرير):
     * - كل نتيجة سابقة تُعد إثباتًا بمستوى "مبتدئ" فقط، لأنها لم تكن محاولة 10 أسئلة بمستوى محدد.
     * - الجلسات السابقة متعددة المهارات تبقى skill_id = NULL و requested_level = NULL (سجل تاريخي).
     * - أدوار النسخ المنشورة سابقًا تبقى min_level = NULL: تعني "أي مستوى مُثبت" وهي القاعدة التي اعتُمدت بها،
     *   وتظهر للمؤسسة كمستويات غير مؤكدة حتى تعيد الاعتماد.
     */
    public function up(): void
    {
        Schema::table('assessment_sessions', function (Blueprint $table) {
            $table->foreignId('skill_id')->nullable()->after('student_profile_id')->constrained('skills')->restrictOnDelete();
            $table->string('requested_level', 20)->nullable()->after('skill_id');
            $table->index(['student_profile_id', 'skill_id', 'requested_level'], 'assessment_sessions_student_skill_level_index');
        });

        Schema::table('skill_assessments', function (Blueprint $table) {
            $table->string('level', 20)->nullable()->after('status');
        });

        Schema::table('project_role_skills', function (Blueprint $table) {
            $table->string('min_level', 20)->nullable()->after('importance');
        });

        Schema::table('project_proposal_versions', function (Blueprint $table) {
            $table->char('confirmation_hash', 64)->nullable()->after('proposal_hash');
        });

        DB::table('skill_assessments')->whereNull('level')->update(['level' => 'beginner']);
        // النسخ السابقة لا مستويات مؤكدة لها؛ بصمتها بصمة المقترح وحده فلن تطابق اعتمادًا جديدًا بمستويات
        DB::table('project_proposal_versions')->update(['confirmation_hash' => DB::raw('proposal_hash')]);
        DB::table('assessment_sessions')->orderBy('id')->select(['id', 'skill_ids'])->chunkById(200, function ($sessions): void {
            foreach ($sessions as $session) {
                $ids = json_decode((string) $session->skill_ids, true);
                if (is_array($ids) && count($ids) === 1) {
                    DB::table('assessment_sessions')->where('id', $session->id)->update(['skill_id' => (int) $ids[0]]);
                }
            }
        });

        Schema::table('project_proposal_versions', function (Blueprint $table) {
            $table->dropUnique('proposal_versions_request_hash_unique');
            $table->unique(['project_request_id', 'confirmation_hash'], 'proposal_versions_request_confirmation_unique');
        });
    }

    public function down(): void
    {
        Schema::table('project_proposal_versions', function (Blueprint $table) {
            $table->dropUnique('proposal_versions_request_confirmation_unique');
            $table->unique(['project_request_id', 'proposal_hash'], 'proposal_versions_request_hash_unique');
            $table->dropColumn('confirmation_hash');
        });

        Schema::table('project_role_skills', function (Blueprint $table) {
            $table->dropColumn('min_level');
        });

        Schema::table('skill_assessments', function (Blueprint $table) {
            $table->dropColumn('level');
        });

        Schema::table('assessment_sessions', function (Blueprint $table) {
            // المفتاح الأجنبي أولًا: قد يعتمد MySQL على الفهرس لتنفيذه
            $table->dropForeign(['skill_id']);
            $table->dropIndex('assessment_sessions_student_skill_level_index');
            $table->dropColumn(['skill_id', 'requested_level']);
        });
    }
};
