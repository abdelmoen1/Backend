<?php

namespace Tests\Feature;

use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_registration_creates_student_profile_and_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Amina Student',
            'email' => 'amina@example.com',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'role' => 'student',
            'google_id' => 'untrusted-google-id',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'تم إنشاء الحساب بنجاح')
            ->assertJsonPath('user.email', 'amina@example.com')
            ->assertJsonPath('user.role', 'student')
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role', 'created_at', 'updated_at']])
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.google_id');

        $user = User::where('email', 'amina@example.com')->firstOrFail();

        $this->assertSame('student', $user->role);
        $this->assertNull($user->google_id);
        $this->assertTrue(Hash::check('SecurePass123', $user->password));
        $this->assertDatabaseHas('student_profiles', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('organization_profiles', ['user_id' => $user->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_organization_registration_creates_organization_profile_and_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Example Organization',
            'email' => 'organization@example.com',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'role' => 'organization',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.role', 'organization')
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role']]);

        $user = User::where('email', 'organization@example.com')->firstOrFail();

        $this->assertSame('organization', $user->role);
        $this->assertDatabaseHas('organization_profiles', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('student_profiles', ['user_id' => $user->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::create([
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'password' => 'SecurePass123',
            'role' => 'student',
        ]);

        $this->postJson('/api/register', $this->registrationData(['email' => 'existing@example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_registration_rejects_invalid_email(): void
    {
        $this->postJson('/api/register', $this->registrationData(['email' => 'not-an-email']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_registration_rejects_invalid_role(): void
    {
        $this->postJson('/api/register', $this->registrationData(['role' => 'admin']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_registration_requires_all_fields(): void
    {
        $this->postJson('/api/register', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password', 'password_confirmation', 'role']);
    }

    public function test_registration_rejects_password_confirmation_mismatch(): void
    {
        $this->postJson('/api/register', $this->registrationData([
            'password_confirmation' => 'DifferentPass123',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('password_confirmation');
    }

    public function test_profile_creation_failure_rolls_back_the_user(): void
    {
        // فشل إنشاء الملف بعد إدراج المستخدم؛ يعمل على أي محرك قاعدة بيانات (لا trigger خاص بـ SQLite)
        StudentProfile::creating(function (): never {
            throw new RuntimeException('profile creation failed');
        });
        $this->withoutExceptionHandling();

        try {
            $this->postJson('/api/register', $this->registrationData());
            $this->fail('The profile insert should fail.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('student_profiles', 0);
        }
    }

    private function registrationData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'role' => 'student',
        ], $overrides);
    }
}
