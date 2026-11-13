<?php

namespace App\Providers;

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
        // Once a day, after a page has gone out, write a backup of the database.
        if (! $this->app->runningInConsole() && ! $this->app->runningUnitTests() && config('app.auto_backup')) {
            $this->app->terminating(fn () => \App\Support\DatabaseBackup::runIfDue());
        }
    }
}
