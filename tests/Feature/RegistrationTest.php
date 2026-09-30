<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
            ->assertJsonPath('message', 'Registration successful.')
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

    public function test_institution_registration_creates_organization_profile_and_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Example Institution',
            'email' => 'institution@example.com',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'role' => 'institution',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.role', 'institution')
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role']]);

        $user = User::where('email', 'institution@example.com')->firstOrFail();

        $this->assertSame('institution', $user->role);
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
        DB::statement("CREATE TRIGGER fail_student_profile_insert BEFORE INSERT ON student_profiles BEGIN SELECT RAISE(ABORT, 'profile creation failed'); END");
        $this->withoutExceptionHandling();

        try {
            $this->postJson('/api/register', $this->registrationData());
            $this->fail('The profile insert should fail.');
        } catch (QueryException) {
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
