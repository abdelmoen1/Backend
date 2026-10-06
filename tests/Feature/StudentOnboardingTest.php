<?php

namespace Tests\Feature;

use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
            ->assertJsonPath('data.proficiency', 'intermediate')
            ->assertJsonPath('data.skill_id', $skill->id);

        $this->assertDatabaseHas('student_skills', [
            'student_profile_id' => $profile->id,
            'skill_id' => $skill->id,
        ]);
    }

    public function test_student_can_update_profile_and_account_name(): void
    {
        $student = User::create([
            'name' => 'Student One',
            'email' => 'student@example.com',
            'password' => 'SecurePass123',
            'role' => 'student',
        ]);
        StudentProfile::create(['user_id' => $student->id]);

        $this->actingAs($student, 'sanctum')
            ->patchJson('/api/student/profile', [
                'name' => 'Updated Student',
                'bio' => 'A student profile bio.',
                'status' => 'graduated',
                'university' => 'Example University',
                'academic_major' => 'Computer Science',
                'graduation_year' => '2026',
                'phone' => '+970599123456',
                'linkedin_url' => 'https://www.linkedin.com/in/student-one',
                'github_url' => 'https://github.com/student-one',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Student')
            ->assertJsonPath('data.bio', 'A student profile bio.')
            ->assertJsonPath('data.status', 'graduated')
            ->assertJsonPath('data.university', 'Example University')
            ->assertJsonPath(
                'data.linkedin_url',
                'https://www.linkedin.com/in/student-one'
            )
            ->assertJsonPath('data.github_url', 'https://github.com/student-one');

        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'name' => 'Updated Student',
        ]);
        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $student->id,
            'academic_major' => 'Computer Science',
            'graduation_year' => '2026',
            'phone' => '+970599123456',
            'linkedin_url' => 'https://www.linkedin.com/in/student-one',
            'github_url' => 'https://github.com/student-one',
        ]);
    }

    public function test_student_can_save_portfolio_details_and_skill_proficiency(): void
    {
        $student = User::create([
            'name' => 'Student One',
            'email' => 'profile-details@example.com',
            'password' => 'SecurePass123',
            'role' => 'student',
        ]);
        StudentProfile::create(['user_id' => $student->id]);
        $skill = Skill::create(['name' => 'Laravel']);

        $this->actingAs($student, 'sanctum')
            ->patchJson('/api/student/profile', [
                'name' => 'Student One',
                'portfolio' => [
                    [
                        'title' => 'Clinic App',
                        'description' => 'Appointment management app.',
                        'url' => 'https://example.com/clinic',
                        'category' => 'web',
                    ],
                ],
                'external_links' => [
                    ['label' => 'Portfolio', 'url' => 'https://example.com'],
                ],
                'certifications' => [
                    [
                        'name' => 'Web Development',
                        'issuer' => 'Example Academy',
                        'issued_at' => '2026-05',
                        'credential_url' => 'https://example.com/certificate',
                    ],
                ],
                'testimonials' => [
                    [
                        'author' => 'Project mentor',
                        'role' => 'Mentor',
                        'quote' => 'Reliable and thoughtful contributor.',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.portfolio.0.title', 'Clinic App')
            ->assertJsonPath('data.external_links.0.label', 'Portfolio')
            ->assertJsonPath('data.certifications.0.issuer', 'Example Academy')
            ->assertJsonPath('data.testimonials.0.author', 'Project mentor');

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/student/skills', [
                'skill_id' => $skill->id,
                'proficiency' => 'advanced',
            ])
            ->assertCreated()
            ->assertJsonPath('data.proficiency', 'advanced')
            ->assertJsonPath('data.skill.name', 'Laravel');

        $this->assertDatabaseHas('student_skills', [
            'student_profile_id' => $student->studentProfile->id,
            'skill_id' => $skill->id,
            'proficiency' => 'advanced',
        ]);

        $this->patchJson("/api/student/skills/{$skill->id}", [
            'proficiency' => 'expert',
        ])
            ->assertOk()
            ->assertJsonPath('data.proficiency', 'expert');

        $this->assertDatabaseHas('student_skills', [
            'student_profile_id' => $student->studentProfile->id,
            'skill_id' => $skill->id,
            'proficiency' => 'expert',
        ]);

        $this->getJson('/api/student/profile')
            ->assertOk()
            ->assertJsonPath('data.skills.0.id', $skill->id)
            ->assertJsonPath('data.skills.0.name', 'Laravel')
            ->assertJsonPath('data.skills.0.proficiency', 'expert')
            ->assertJsonPath('data.portfolio.0.category', 'web')
            ->assertJsonPath('data.certifications.0.name', 'Web Development');

        $this->deleteJson("/api/student/skills/{$skill->id}")
            ->assertOk();

        $this->assertDatabaseMissing('student_skills', [
            'student_profile_id' => $student->studentProfile->id,
            'skill_id' => $skill->id,
        ]);
    }

    public function test_student_profile_rejects_invalid_skill_proficiency(): void
    {
        $student = User::create([
            'name' => 'Student One',
            'email' => 'invalid-skill-level@example.com',
            'password' => 'SecurePass123',
            'role' => 'student',
        ]);
        StudentProfile::create(['user_id' => $student->id]);
        $skill = Skill::create(['name' => 'Laravel']);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/student/skills', [
                'skill_id' => $skill->id,
                'proficiency' => 'guru',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('proficiency');

        $this->assertDatabaseCount('student_skills', 0);
    }

    public function test_student_can_upload_a_profile_image(): void
    {
        Storage::fake('public');

        $student = User::create([
            'name' => 'Student One',
            'email' => 'student@example.com',
            'password' => 'SecurePass123',
            'role' => 'student',
        ]);
        StudentProfile::create(['user_id' => $student->id]);

        $response = $this->actingAs($student, 'sanctum')
            ->post('/api/student/profile', [
                '_method' => 'PATCH',
                'image' => UploadedFile::fake()->create(
                    'profile.png',
                    10,
                    'image/png'
                ),
            ], [
                'Accept' => 'application/json',
            ]);

        $response->assertOk();

        $profile = StudentProfile::where('user_id', $student->id)->firstOrFail();
        Storage::disk('public')->assertExists($profile->image_path);
        $response->assertJsonPath('data.image_path', $profile->image_path)
            ->assertJsonPath(
                'data.image_url',
                route('api.profile-images.student', [
                    'filename' => basename($profile->image_path),
                ])
            );

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/profile')
            ->assertOk()
            ->assertJsonPath('data.image_path', $profile->image_path)
            ->assertJsonPath(
                'data.image_url',
                route('api.profile-images.student', [
                    'filename' => basename($profile->image_path),
                ])
            );
    }

    public function test_student_profile_images_are_publicly_served_by_laravel(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put(
            'student-profiles/profile.png',
            base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/pS8AAAAASUVORK5CYII=',
                true
            )
        );

        $this->get('/api/profile-images/student/profile.png')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_student_profile_image_endpoint_rejects_missing_and_unsafe_files(): void
    {
        Storage::fake('public');

        $this->get('/api/profile-images/student/missing.png')
            ->assertNotFound();

        $this->get('/api/profile-images/student/..%2F.env')
            ->assertNotFound();
    }
}
