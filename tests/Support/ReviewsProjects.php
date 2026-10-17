<?php

namespace Tests\Support;

use App\Models\ProjectOpportunity;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;

/**
 * خطوات مدير المنصة عبر مساراته الحقيقية: الموافقة على مشروع بانتظار المراجعة وتعيين قائد فريق له.
 */
trait ReviewsProjects
{
    protected function platformAdmin(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'platform-admin@example.test'],
            ['name' => 'Platform Admin', 'password' => 'SecurePass123', 'role' => User::ROLE_ADMIN],
        );
    }

    protected function adminApprove(ProjectOpportunity $opportunity): ProjectOpportunity
    {
        $this->actingAs($this->platformAdmin(), 'sanctum')
            ->postJson("/api/admin/projects/{$opportunity->id}/review", ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', ProjectOpportunity::STATUS_PUBLISHED);
        $this->app['auth']->forgetGuards();

        return $opportunity->fresh();
    }

    /** طالب مؤهل للقيادة (ملف مكتمل ومهارة مثبتة) يعيّنه مدير المنصة قائدًا للمشروع */
    protected function assignedLeader(ProjectOpportunity $opportunity, ?string $email = null): User
    {
        $email ??= 'leader-'.uniqid().'@example.test';
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'SecurePass123', 'role' => User::ROLE_STUDENT]);
        $profile = StudentProfile::create([
            'user_id' => $user->id,
            'academic_major' => 'هندسة البرمجيات',
            'professional_specialization' => 'backend_development',
            'weekly_availability_hours' => 30,
            'onboarding_completed' => true,
        ]);
        $skill = Skill::query()->firstOrCreate(['name' => 'Git']);
        $profile->studentSkills()->create(['skill_id' => $skill->id, 'proficiency' => 'advanced']);
        $profile->skillAssessments()->create([
            'skill_id' => $skill->id,
            'status' => 'passed',
            'level' => 'advanced',
            'source' => 'admin_review',
            'assessed_at' => now(),
        ]);

        $this->actingAs($this->platformAdmin(), 'sanctum')
            ->postJson("/api/admin/projects/{$opportunity->id}/leader", ['user_id' => $user->id])
            ->assertOk();
        $this->app['auth']->forgetGuards();

        return $user->load('studentProfile');
    }
}
