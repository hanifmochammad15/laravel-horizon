<?php

namespace App\Providers;

use App\Services\LogService;
use App\Services\ElasticsearchService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind('ES',ElasticsearchService::class);
        $this->app->bind('Log',LogService::class);
    }    

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
