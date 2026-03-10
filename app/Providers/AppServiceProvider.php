<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
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
        if ($this->app->runningInConsole()) {
            return;
        }

        URL::macro('appRoute', function (string $name, array $parameters = []): string {
            $path = app('url')->route($name, $parameters, false);
            $base = rtrim((string) request()->getBaseUrl(), '/');
            return ($base !== '' ? $base : '') . $path;
        });
    }
}
