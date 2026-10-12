<?php

namespace App\Providers;

use App\Contracts\AIProvider;
use App\Contracts\ProjectProposalGenerator;
use App\Services\AI\GeminiProjectProposalGenerator;
use App\Services\AI\GeminiProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AIProvider::class, GeminiProvider::class);
        $this->app->bind(
            ProjectProposalGenerator::class,
            GeminiProjectProposalGenerator::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(
            Str::lower((string) $request->input('email')).'|'.$request->ip()
        ));
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('oauth', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        // بدء وتسليم اختبارات المهارات لكل طالب
        RateLimiter::for('assessments', fn (Request $request) => Limit::perMinute(30)->by((string) $request->user()?->id ?: $request->ip()));
    }
}
