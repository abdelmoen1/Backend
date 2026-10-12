<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Projects\ProjectStaffing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** توصيات الذكاء الاصطناعي بمستوى المهارات الإلزامية: تُنظَّف وتُعرض كتوصية فقط، ولا تُخترع عند غيابها. */
class ProjectStaffingLevelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_recommended_levels_are_kept_only_for_mandatory_skills_with_valid_levels(): void
    {
        foreach (['Laravel', 'REST API', 'MySQL'] as $name) {
            Skill::create(['name' => $name]);
        }

        $roles = ProjectStaffing::sanitize([[
            'category' => 'backend_developer',
            'title' => 'مطوّر الخدمات الخلفية',
            'responsibilities' => 'بناء واجهات البيانات.',
            'mandatory_skills' => ['Laravel', 'REST API'],
            'desirable_skills' => ['MySQL'],
            'mandatory_skill_levels' => [
                ['skill' => 'laravel', 'level' => 'intermediate'],
                // مكرر: الأول هو المعتمد
                ['skill' => 'Laravel', 'level' => 'advanced'],
                // مهارة مرغوبة وليست إلزامية
                ['skill' => 'MySQL', 'level' => 'advanced'],
                // مستوى غير معروف
                ['skill' => 'REST API', 'level' => 'guru'],
                'not-an-object',
            ],
        ]]);
        $this->assertSame([['skill' => 'Laravel', 'level' => 'intermediate']], $roles[0]['mandatory_skill_levels']);

        $staffing = ProjectStaffing::fromProposal(['staffing_requirements' => $roles]);
        $this->assertSame('intermediate', $staffing['roles'][0]['mandatory_skills'][0]['recommended_level']);
        // لا توصية = null، ولا يُفترض مستوى
        $this->assertNull($staffing['roles'][0]['mandatory_skills'][1]['recommended_level']);
        $this->assertArrayNotHasKey('recommended_level', $staffing['roles'][0]['desirable_skills'][0]);
        $this->assertArrayNotHasKey('min_level', $staffing['roles'][0]['mandatory_skills'][0]);

        // مقترحات سابقة بلا توصيات تبقى صالحة
        $legacy = ProjectStaffing::fromProposal(['staffing_requirements' => [['title' => 'x', 'responsibilities' => 'y', 'mandatory_skills' => ['Laravel']]]]);
        $this->assertNull($legacy['roles'][0]['mandatory_skills'][0]['recommended_level']);
    }
}
