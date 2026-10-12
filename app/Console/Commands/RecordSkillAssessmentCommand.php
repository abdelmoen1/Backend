<?php

namespace App\Console\Commands;

use App\Models\Skill;
use App\Models\SkillAssessment;
use App\Models\User;
use App\Services\Assessments\SkillLevels;
use Illuminate\Console\Command;

/**
 * تسجيل نتيجة تقييم مهارة لطالب من إدارة المنصة (مثل مراجعة عملية أو مقابلة)، بجانب اختبارات المنصة.
 * النتيجة تُسجَّل كما هي بالمستوى المحدد صراحةً؛ لا يُعتبر الطالب متحققًا من مهارة إلا بنتيجة "passed"، وبذلك
 * المستوى فقط. نتيجة راسبة لا تلغي نجاحًا سابقًا بأي مستوى.
 */
class RecordSkillAssessmentCommand extends Command
{
    protected $signature = 'skills:record-assessment
        {email : بريد الطالب}
        {skill : اسم المهارة كما في قائمة المهارات}
        {--failed : تسجيل التقييم كراسب بدل ناجح}
        {--level= : المستوى الذي تثبته النتيجة: beginner أو intermediate أو advanced (إلزامي)}
        {--score= : الدرجة من 0 إلى 100 (اختياري)}
        {--source=admin_review : مصدر التقييم}
        {--notes= : ملاحظات}';

    protected $description = 'Record a verified skill assessment result for a student.';

    public function handle(): int
    {
        $student = User::query()->where('email', $this->argument('email'))->where('role', 'student')->first()?->studentProfile;
        if (! $student) {
            $this->error('No student profile found for that email.');

            return self::FAILURE;
        }

        $skill = Skill::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($this->argument('skill')))])->first();
        if (! $skill) {
            $this->error('Unknown skill. Use a name from the skills table.');

            return self::FAILURE;
        }

        if (! $student->studentSkills()->where('skill_id', $skill->id)->exists()) {
            $this->warn('The student has not selected this skill; it will not count until they add it to their profile.');
        }

        if ($this->option('failed') && $student->skillAssessments()->where('skill_id', $skill->id)->where('status', SkillAssessment::STATUS_PASSED)->exists()) {
            $this->warn('The student already has a passed result for this skill. Results are append-only, so this failed record does not revoke it.');
        }

        // لا يُفترض مستوى ضمنيًا: الإدارة تحدد ما أثبتته المراجعة
        $level = $this->option('level');
        if (! SkillLevels::valid($level)) {
            $this->error('Level is required: beginner, intermediate or advanced.');

            return self::FAILURE;
        }

        $score = $this->option('score');
        if ($score !== null && (! ctype_digit((string) $score) || (int) $score > 100)) {
            $this->error('Score must be an integer between 0 and 100.');

            return self::FAILURE;
        }

        $assessment = $student->skillAssessments()->create([
            'skill_id' => $skill->id,
            'status' => $this->option('failed') ? SkillAssessment::STATUS_FAILED : SkillAssessment::STATUS_PASSED,
            'level' => $level,
            'score' => $score === null ? null : (int) $score,
            'source' => mb_substr((string) $this->option('source'), 0, 40),
            'notes' => $this->option('notes'),
            'assessed_at' => now(),
        ]);

        $this->info("Recorded {$assessment->status} {$level} assessment #{$assessment->id} for {$skill->name}.");

        return self::SUCCESS;
    }
}
