<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

/**
 * تسجيل الدخول/إنشاء الحساب عبر Google.
 *
 * - redirect/callback على مجموعة web: Socialite يحفظ state في الجلسة ويتحقق منه مرة واحدة.
 * - الهوية تُحدَّد بـ sub (google_id) وليس بالبريد.
 * - الربط بحساب موجود بنفس البريد يتم فقط عندما يكون Google مرجعًا موثوقًا لذلك البريد.
 * - لا يُمرَّر رمز Sanctum في الرابط؛ نمرر رمز تبادل قصير العمر لمرة واحدة.
 */
class GoogleAuthController extends Controller
{
    private const SESSION_KEY = 'google_oauth';

    private const EXCHANGE_PREFIX = 'google_oauth_exchange:';

    private const EXCHANGE_TTL_SECONDS = 60;

    private const ROLES = ['student', 'organization'];

    private const GOOGLE_EMAIL_DOMAINS = ['gmail.com', 'googlemail.com'];

    public function redirect(Request $request): RedirectResponse
    {
        $this->ensureConfigured();

        $intent = $request->query('intent') === 'register' ? 'register' : 'login';
        $role = $request->query('role');

        if ($intent === 'register' && ! in_array($role, self::ROLES, true)) {
            return $this->failed('register', 'invalid_request');
        }

        $request->session()->put(self::SESSION_KEY, [
            'intent' => $intent,
            'role' => $intent === 'register' ? $role : null,
        ]);

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->ensureConfigured();

        $context = $request->session()->pull(self::SESSION_KEY);
        $intent = ($context['intent'] ?? null) === 'register' ? 'register' : 'login';
        $role = in_array($context['role'] ?? null, self::ROLES, true) ? $context['role'] : null;

        if ($request->filled('error')) {
            return $this->failed(
                $intent,
                $request->query('error') === 'access_denied' ? 'cancelled' : 'provider_error'
            );
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return $this->failed($intent, 'invalid_state');
        } catch (Throwable $exception) {
            Log::warning('Google OAuth callback failed.', [
                'exception' => $exception::class,
            ]);

            return $this->failed($intent, 'provider_error');
        }

        $result = $this->resolveUser($googleUser, $intent, $role);
        if (isset($result['error'])) {
            return $this->failed($intent, $result['error']);
        }

        $code = Str::random(64);
        Cache::put(
            self::EXCHANGE_PREFIX.hash('sha256', $code),
            $result['user']->id,
            self::EXCHANGE_TTL_SECONDS
        );

        return redirect()->away(
            $this->frontendUrl('/auth/google/callback').'?'.http_build_query(['code' => $code])
        );
    }

    public function exchange(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:64'],
        ], [
            'code.*' => 'رمز تسجيل الدخول غير صالح.',
        ]);

        // pull يجعل الرمز صالحًا لمرة واحدة فقط
        $userId = Cache::pull(self::EXCHANGE_PREFIX.hash('sha256', $validated['code']));
        $user = $userId ? User::find($userId) : null;

        if (! $user) {
            return response()->json([
                'message' => 'انتهت صلاحية رمز تسجيل الدخول. حاول تسجيل الدخول عبر Google مرة أخرى.',
                'code' => 'oauth_code_invalid',
            ], 422);
        }

        return response()->json([
            'message' => 'تم تسجيل الدخول بنجاح.',
            'user' => new UserResource($user->load('studentProfile', 'organizationProfile')),
            'token' => $user->createToken('auth_token')->plainTextToken,
        ]);
    }

    /**
     * @return array{user?: User, error?: string}
     */
    private function resolveUser(SocialiteUser $googleUser, string $intent, ?string $role): array
    {
        $raw = $googleUser->getRaw();
        $googleId = trim((string) $googleUser->getId());
        $email = Str::lower(trim((string) $googleUser->getEmail()));
        $emailVerified = filter_var($raw['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($googleId === '' || $email === '' || ! $emailVerified) {
            return ['error' => 'unverified_email'];
        }

        try {
            return DB::transaction(function () use ($googleUser, $raw, $googleId, $email, $intent, $role): array {
                $user = User::where('google_id', $googleId)->lockForUpdate()->first();
                if ($user) {
                    return ['user' => $this->syncGoogleProfile($user, $googleUser, $email)];
                }

                $existing = User::where('email', $email)->lockForUpdate()->first();
                if ($existing) {
                    // حساب مربوط بحساب Google آخر، أو بريد لا يملك Google سلطة التحقق منه
                    if ($existing->google_id !== null || ! $this->googleIsAuthoritativeFor($email, $raw)) {
                        return ['error' => 'account_conflict'];
                    }

                    // الربط لا يغيّر الدور ولا كلمة المرور الحالية
                    $existing->forceFill(['google_id' => $googleId])->save();

                    return ['user' => $this->syncGoogleProfile($existing, $googleUser, $email)];
                }

                if ($intent !== 'register' || $role === null) {
                    return ['error' => 'no_account'];
                }

                $user = User::create([
                    'name' => trim((string) $googleUser->getName()) ?: Str::before($email, '@'),
                    'email' => $email,
                    'password' => null,
                    'role' => $role,
                    'google_id' => $googleId,
                ]);
                $user->ensureRoleProfile();

                return ['user' => $this->syncGoogleProfile($user, $googleUser, $email)];
            });
        } catch (UniqueConstraintViolationException) {
            // طلبان متزامنان لنفس الحساب: نعيد الحساب الذي أُنشئ أولًا
            $user = User::where('google_id', $googleId)->first();

            return $user ? ['user' => $user] : ['error' => 'account_conflict'];
        }
    }

    /**
     * يحفظ صورة Google (روابط https فقط) ويعلّم البريد متحققًا منه عندما يطابق البريد الذي تحقق منه Google
     * (قد يختلف لحساب مربوط غيّر بريده في Google؛ عندها لا نعلّم بريد الحساب كمتحقق).
     */
    private function syncGoogleProfile(User $user, SocialiteUser $googleUser, string $verifiedEmail): User
    {
        $avatar = trim((string) $googleUser->getAvatar());
        $changes = [];
        if (str_starts_with($avatar, 'https://') && filter_var($avatar, FILTER_VALIDATE_URL) && strlen($avatar) <= 1024) {
            $changes['avatar'] = $avatar;
        }
        if ($user->email_verified_at === null && Str::lower($user->email) === $verifiedEmail) {
            $changes['email_verified_at'] = now();
        }

        if ($changes !== []) {
            $user->forceFill($changes)->save();
        }

        return $user;
    }

    /**
     * Google مرجع موثوق للبريد في حسابات Gmail أو عندما يطابق نطاق Workspace (hd) نطاق البريد.
     */
    private function googleIsAuthoritativeFor(string $email, array $raw): bool
    {
        $domain = Str::after($email, '@');
        $hostedDomain = Str::lower((string) ($raw['hd'] ?? ''));

        return in_array($domain, self::GOOGLE_EMAIL_DOMAINS, true)
            || ($hostedDomain !== '' && $hostedDomain === $domain);
    }

    private function failed(string $intent, string $code): RedirectResponse
    {
        $path = $intent === 'register' ? '/register' : '/login';

        return redirect()->away(
            $this->frontendUrl($path).'?'.http_build_query(['oauth_error' => $code])
        );
    }

    private function frontendUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }

    private function ensureConfigured(): void
    {
        abort_unless(
            filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'))
            && filled(config('app.frontend_url')),
            503,
            'Google sign-in is not configured.'
        );
    }
}
