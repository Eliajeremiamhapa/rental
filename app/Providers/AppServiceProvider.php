<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // General API guard for authenticated traffic: generous enough for a
        // screen that fans out to several endpoints, but bounded so a runaway
        // client cannot hammer the database.
        RateLimiter::for('api', function (Request $request) {
            $userId = $request->user()?->id;

            return $userId
                ? Limit::perMinute(180)->by('user:'.$userId)
                : Limit::perMinute(60)->by('ip:'.$request->ip());
        });
    }
}
