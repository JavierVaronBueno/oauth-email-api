<?php

namespace App\Providers;

use App\Contracts\OAuthServiceInterface;
use App\Services\GoogleOAuthService;
use App\Services\MicrosoftOAuthService;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for registering OAuth services.
 *
 * This provider is responsible for binding the OAuthServiceInterface
 * to its concrete implementations within the Laravel service container.
 */
class OAuthServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * This method is where you can bind interfaces to implementations,
     * register singletons, and perform other service container registrations.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(GoogleOAuthService::class);
        $this->app->singleton(MicrosoftOAuthService::class);
    }

    /**
     * Bootstrap any application services.
     *
     * This method is called after all other service providers have been registered,
     * meaning you have access to all other services that have been registered
     * by the framework and other service providers.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
