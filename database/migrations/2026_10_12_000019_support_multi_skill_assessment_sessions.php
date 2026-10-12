<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تقييم واحد يغطي حتى 3 مهارات بمستوى مستقل لكل مهارة:
     * - assessment_sessions.skill_levels: {skill_id: level} كما طلبه الطالب (طلب صعوبة، لا إثبات). skill_id و
     *   requested_level يبقيان للمحاولة ذات المهارة الواحدة وللسجل السابق.
     * - assessment_session_answers.skill_id يصبح اختياريًا: السؤال الكتابي قد يقيّم عدة مهارات، ولكل معيار في
     *   معايير تصحيحه مهارته (داخل questions و grade). أسئلة المهارة الواحدة تحتفظ بمهارتها كما هي.
     */
    public function up(): void
    {
        Schema::table('assessment_sessions', function (Blueprint $table) {
            $table->json('skill_levels')->nullable()->after('skill_ids');
        });

        Schema::table('assessment_session_answers', function (Blueprint $table) {
            $table->unsignedBigInteger('skill_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // التراجع يفشل عمدًا إن وُجدت إجابات كتابية بلا مهارة، بدل حذفها
        Schema::table('assessment_session_answers', function (Blueprint $table) {
            $table->unsignedBigInteger('skill_id')->nullable(false)->change();
        });

        Schema::table('assessment_sessions', function (Blueprint $table) {
            $table->dropColumn('skill_levels');
        });
    }
};
