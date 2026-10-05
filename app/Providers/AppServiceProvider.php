<?php

namespace App\Providers;

use App\Contracts\ProjectProposalGenerator;
use App\Services\AI\GeminiProjectProposalGenerator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
