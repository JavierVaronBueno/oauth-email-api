<?php

namespace App\Providers;

use App\Contracts\OAuthServiceInterface;
use App\Services\GoogleOAuthService;
use App\Services\MicrosoftOAuthService;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for registering OAuth services.
 */
class OAuthServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(OAuthServiceInterface::class, GoogleOAuthService::class);
        $this->app->bind(OAuthServiceInterface::class, MicrosoftOAuthService::class);
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
