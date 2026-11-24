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
        // nama pendek untuk Blade: {{ Istilah::severity($x) }}, {{ Tahap::label($t) }}
        $loader = \Illuminate\Foundation\AliasLoader::getInstance();
        $loader->alias('Istilah', \App\Support\Istilah::class);
        $loader->alias('Tahap', \App\Support\Tahap::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
