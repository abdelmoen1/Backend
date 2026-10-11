<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND = 'https://frontend.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.frontend_url' => self::FRONTEND,
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'https://api.example.test/auth/google/callback',
        ]);
    }

    public function test_redirect_starts_google_oauth_with_state_and_stores_intent(): void
    {
        $response = $this->get('/auth/google/redirect?intent=register&role=organization');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth', $location);
        $this->assertStringContainsString('client_id=test-client-id', $location);
        $this->assertStringContainsString('state=', $location);
        $response->assertSessionHas('google_oauth', ['intent' => 'register', 'role' => 'organization']);
        $response->assertSessionHas('state');
    }

    public function test_register_intent_rejects_privileged_or_unknown_roles(): void
    {
        $this->get('/auth/google/redirect?intent=register&role=admin')
            ->assertRedirect(self::FRONTEND.'/register?oauth_error=invalid_request');
    }

    public function test_unconfigured_google_sign_in_returns_503(): void
    {
        config(['services.google.client_id' => null]);

        $this->get('/auth/google/redirect')->assertStatus(503);
    }

    public function test_invalid_state_is_rejected_without_creating_users(): void
    {
        $this->mockGoogle(fn ($provider) => $provider->shouldReceive('user')
            ->andThrow(new InvalidStateException));

        $this->withSession(['google_oauth' => ['intent' => 'register', 'role' => 'student']])
            ->get('/auth/google/callback?code=abc&state=forged')
            ->assertRedirect(self::FRONTEND.'/register?oauth_error=invalid_state');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_real_socialite_rejects_a_callback_without_matching_session_state(): void
    {
        $this->withSession(['state' => 'expected-state'])
            ->get('/auth/google/callback?code=abc&state=other-state')
            ->assertRedirect(self::FRONTEND.'/login?oauth_error=invalid_state');
    }

    public function test_user_cancellation_and_provider_errors_are_reported(): void
    {
        $this->get('/auth/google/callback?error=access_denied')
            ->assertRedirect(self::FRONTEND.'/login?oauth_error=cancelled');

        $this->get('/auth/google/callback?error=server_error')
            ->assertRedirect(self::FRONTEND.'/login?oauth_error=provider_error');
    }

    public function test_register_creates_account_with_chosen_role_and_code_is_single_use(): void
    {
        $this->mockGoogle(fn ($provider) => $provider->shouldReceive('user')
            ->andReturn($this->googleUser('sub-123', 'New.Student@gmail.com')));

        $response = $this->withSession(['google_oauth' => ['intent' => 'register', 'role' => 'student']])
            ->get('/auth/google/callback?code=abc&state=ok');

        $code = $this->exchangeCodeFrom($response->headers->get('Location'));
        $user = User::where('google_id', 'sub-123')->firstOrFail();
        $this->assertSame('new.student@gmail.com', $user->email);
        $this->assertSame('student', $user->role);
        $this->assertNull($user->password);
        $this->assertDatabaseHas('student_profiles', ['user_id' => $user->id]);

        $this->postJson('/api/auth/google/exchange', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', 'student')
            ->assertJsonStructure(['token']);

        $this->postJson('/api/auth/google/exchange', ['code' => $code])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'oauth_code_invalid');
    }

    public function test_returning_google_user_signs_in_without_duplicate_accounts(): void
    {
        $existing = User::create([
            'name' => 'Linked',
            'email' => 'linked@gmail.com',
            'password' => null,
            'role' => 'organization',
            'google_id' => 'sub-linked',
        ]);
        $this->mockGoogle(fn ($provider) => $provider->shouldReceive('user')
            ->andReturn($this->googleUser('sub-linked', 'linked@gmail.com')));

        // حتى مع intent=register ودور مختلف: الدور الحالي محفوظ ولا يُنشأ حساب جديد
        $this->withSession(['google_oauth' => ['intent' => 'register', 'role' => 'student']])
            ->get('/auth/google/callback?code=abc&state=ok')
            ->assertRedirectContains(self::FRONTEND.'/auth/google/callback?code=');

        $this->assertDatabaseCount('users', 1);
        $this->assertSame('organization', $existing->fresh()->role);
    }

    public function test_login_without_an_account_does_not_create_one(): void
    {
        $this->mockGoogle(fn ($provider) => $provider->shouldReceive('user')
            ->andReturn($this->googleUser('sub-new', 'someone@gmail.com')));

        $this->withSession(['google_oauth' => ['intent' => 'login', 'role' => null]])
            ->get('/auth/google/callback?code=abc&state=ok')
            ->assertRedirect(self::FRONTEND.'/login?oauth_error=no_account');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_unverified_google_email_is_rejected(): void
    {
        $this->mockGoogle(fn ($provider) => $provider->shouldReceive('user')
            ->andReturn($this->googleUser('sub-x', 'x@gmail.com', ['email_verified' => false])));

        $this->withSession(['google_oauth' => ['intent' => 'register', 'role' => 'student']])
            ->get('/auth/google/callback?code=abc&state=ok')
            ->assertRedirect(self::FRONTEND.'/register?oauth_error=unverified_email');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_gmail_password_account_is_linked_without_changing_role_or_password(): void
    {
        $existing = User::create([
            'name' => 'Password user',
            'email' => 'owner@gmail.com',
            'password' => 'SecurePass123',
            'role' => 'organization',
        ]);
        $this->mockGoogle(fn ($provider) => $provider->shouldReceive('user')
            ->andReturn($this->googleUser('sub-owner', 'owner@gmail.com')));

        $this->withSession(['google_oauth' => ['intent' => 'login', 'role' => null]])
            ->get('/auth/google/callback?code=abc&state=ok')
            ->assertRedirectContains(self::FRONTEND.'/auth/google/callback?code=');

        $fresh = $existing->fresh();
        $this->assertSame('sub-owner', $fresh->google_id);
        $this->assertSame('organization', $fresh->role);
        $this->assertSame($existing->password, $fresh->password);
    }

    public function test_non_google_authoritative_email_is_not_auto_linked(): void
    {
        User::create([
            'name' => 'Company user',
            'email' => 'user@company.example',
            'password' => 'SecurePass123',
            'role' => 'student',
        ]);
        $this->mockGoogle(fn ($provider) => $provider->shouldReceive('user')
            ->andReturn($this->googleUser('sub-company', 'user@company.example')));

        $this->withSession(['google_oauth' => ['intent' => 'login', 'role' => null]])
            ->get('/auth/google/callback?code=abc&state=ok')
            ->assertRedirect(self::FRONTEND.'/login?oauth_error=account_conflict');

        $this->assertNull(User::where('email', 'user@company.example')->value('google_id'));
    }

    public function test_workspace_hosted_domain_allows_linking(): void
    {
        User::create([
            'name' => 'Workspace user',
            'email' => 'staff@school.example',
            'password' => 'SecurePass123',
            'role' => 'student',
        ]);
        $this->mockGoogle(fn ($provider) => $provider->shouldReceive('user')
            ->andReturn($this->googleUser('sub-ws', 'staff@school.example', ['hd' => 'school.example'])));

        $this->withSession(['google_oauth' => ['intent' => 'login', 'role' => null]])
            ->get('/auth/google/callback?code=abc&state=ok')
            ->assertRedirectContains(self::FRONTEND.'/auth/google/callback?code=');

        $this->assertSame('sub-ws', User::where('email', 'staff@school.example')->value('google_id'));
    }

    public function test_email_linked_to_a_different_google_account_is_a_conflict(): void
    {
        User::create([
            'name' => 'Linked elsewhere',
            'email' => 'taken@gmail.com',
            'password' => null,
            'role' => 'student',
            'google_id' => 'sub-original',
        ]);
        $this->mockGoogle(fn ($provider) => $provider->shouldReceive('user')
            ->andReturn($this->googleUser('sub-other', 'taken@gmail.com')));

        $this->withSession(['google_oauth' => ['intent' => 'login', 'role' => null]])
            ->get('/auth/google/callback?code=abc&state=ok')
            ->assertRedirect(self::FRONTEND.'/login?oauth_error=account_conflict');

        $this->assertSame('sub-original', User::where('email', 'taken@gmail.com')->value('google_id'));
    }

    public function test_exchange_rejects_unknown_or_malformed_codes(): void
    {
        $this->postJson('/api/auth/google/exchange', ['code' => str_repeat('a', 64)])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'oauth_code_invalid');

        $this->postJson('/api/auth/google/exchange', ['code' => 'short'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    private function mockGoogle(callable $configure): void
    {
        $provider = Mockery::mock();
        $configure($provider);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    private function googleUser(string $sub, string $email, array $raw = []): GoogleUser
    {
        $raw = array_merge(['sub' => $sub, 'email' => $email, 'email_verified' => true], $raw);

        return (new GoogleUser)->setRaw($raw)->map([
            'id' => $sub,
            'name' => 'Google Person',
            'email' => $email,
        ]);
    }

    private function exchangeCodeFrom(string $location): string
    {
        $this->assertStringStartsWith(self::FRONTEND.'/auth/google/callback?code=', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query['code'];
    }
}
