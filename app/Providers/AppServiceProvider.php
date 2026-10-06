<?php

namespace App\Providers;

use App\Contracts\AIProvider;
use App\Contracts\ProjectProposalGenerator;
use App\Services\AI\GeminiProjectProposalGenerator;
use App\Services\AI\GeminiProvider;
use Illuminate\Support\ServiceProvider;

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
        //
    }
}
