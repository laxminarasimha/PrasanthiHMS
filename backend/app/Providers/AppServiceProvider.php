<?php

namespace App\Providers;

use App\Models\DayClose;
use App\Models\PatientAccount;
use App\Support\DatabaseBackup;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

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
        // Money records of a closed day cannot be added to or changed.
        DayClose::lockMoney();

        // A sign-in that has not been used for a long while stops working: a phone that was lost or a computer
        // nobody uses any more does not stay signed in for good. Staff: 30 days unused. The patient app, which
        // people open far less often: 180 days.
        Sanctum::authenticateAccessTokensUsing(function ($token, bool $valid) {
            $days = $token->tokenable instanceof PatientAccount ? 180 : 30;

            return $valid && ($token->last_used_at ?? $token->created_at)?->gt(now()->subDays($days));
        });

        // Once a day, after a page has gone out, write a backup of the database.
        if (! $this->app->runningInConsole() && ! $this->app->runningUnitTests() && config('app.auto_backup')) {
            $this->app->terminating(fn () => DatabaseBackup::runIfDue());
        }
    }
}
