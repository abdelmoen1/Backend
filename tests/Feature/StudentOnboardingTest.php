<?php

namespace Tests\Feature;

use App\Models\StudentProfile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_student_can_complete_onboarding_without_selecting_a_user_id(): void
    {
        $student = User::create([
            'name' => 'Student One',
            'email' => 'student@example.com',
            'password' => 'SecurePass123',
            'role' => 'student',
        ]);
        $otherStudent = User::create([
            'name' => 'Student Two',
            'email' => 'other@example.com',
            'password' => 'SecurePass123',
            'role' => 'student',
        ]);
        StudentProfile::create(['user_id' => $student->id]);
        StudentProfile::create(['user_id' => $otherStudent->id]);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/student/onboarding/complete', [
                'student_id' => $otherStudent->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.onboarding_completed', true);

        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $student->id,
            'onboarding_completed' => true,
        ]);
        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $otherStudent->id,
            'onboarding_completed' => false,
        ]);
    }

    public function test_me_returns_the_backend_onboarding_state(): void
    {
        $student = User::create([
            'name' => 'Student One',
            'email' => 'student@example.com',
            'password' => 'SecurePass123',
            'role' => 'student',
        ]);
        StudentProfile::create([
            'user_id' => $student->id,
            'onboarding_completed' => true,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.onboarding_completed', true);
    }

    public function test_student_can_save_a_skill_without_timestamp_columns(): void
    {
        $student = User::create([
            'name' => 'Student One',
            'email' => 'student@example.com',
            'password' => 'SecurePass123',
            'role' => 'student',
        ]);
        $profile = StudentProfile::create(['user_id' => $student->id]);
        $skill = Skill::create(['name' => 'Communication']);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/student/skills', ['skill_id' => $skill->id])
            ->assertCreated()
            ->assertJsonMissingPath('data.proficiency')
            ->assertJsonPath('data.skill_id', $skill->id);

        $this->assertDatabaseHas('student_skills', [
            'student_profile_id' => $profile->id,
            'skill_id' => $skill->id,
        ]);
    }
}
