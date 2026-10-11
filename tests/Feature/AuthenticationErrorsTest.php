<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationErrorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_wrong_credentials_returns_a_generic_arabic_error(): void
    {
        User::create([
            'name' => 'Student',
            'email' => 'student@example.com',
            'password' => 'SecurePass123',
            'role' => 'student',
        ]);

        foreach (['student@example.com', 'missing@example.com'] as $email) {
            $this->postJson('/api/login', ['email' => $email, 'password' => 'WrongPass123'])
                ->assertUnprocessable()
                ->assertJsonPath('errors.email.0', 'البريد الإلكتروني أو كلمة المرور غير صحيحة.')
                ->assertJsonMissingPath('token');
        }
    }

    public function test_google_only_account_cannot_log_in_with_a_password(): void
    {
        User::create([
            'name' => 'Google user',
            'email' => 'google@example.com',
            'password' => null,
            'role' => 'student',
            'google_id' => 'google-sub-1',
        ]);

        $this->postJson('/api/login', ['email' => 'google@example.com', 'password' => 'anything1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_login_validation_messages_are_arabic(): void
    {
        $this->postJson('/api/login', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'البريد الإلكتروني مطلوب.')
            ->assertJsonPath('errors.password.0', 'كلمة المرور مطلوبة.');

        $this->postJson('/api/login', ['email' => 'not-an-email', 'password' => 'x'])
            ->assertJsonPath('errors.email.0', 'صيغة البريد الإلكتروني غير صحيحة.');
    }

    public function test_login_is_rate_limited_per_email_and_ip(): void
    {
        $payload = ['email' => 'limited@example.com', 'password' => 'WrongPass123'];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', $payload)->assertUnprocessable();
        }

        $this->postJson('/api/login', $payload)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');
    }

    public function test_registration_errors_are_arabic_and_field_specific(): void
    {
        User::create([
            'name' => 'Existing',
            'email' => 'taken@example.com',
            'password' => 'SecurePass123',
            'role' => 'student',
        ]);

        $this->postJson('/api/register', [
            'name' => 'New user',
            'email' => 'taken@example.com',
            'password' => 'abcdefgh',
            'password_confirmation' => 'different1',
            'role' => 'student',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'هذا البريد الإلكتروني مسجّل مسبقًا.')
            ->assertJsonPath('errors.password.0', 'كلمة المرور يجب أن تحتوي على رقم واحد على الأقل.')
            ->assertJsonPath('errors.password_confirmation.0', 'تأكيد كلمة المرور غير مطابق.');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_registration_is_rate_limited_per_ip(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/register', [])->assertUnprocessable();
        }

        $this->postJson('/api/register', [])->assertTooManyRequests();
    }
}
