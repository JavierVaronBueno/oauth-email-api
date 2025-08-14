<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OAuthEmailController;

/*
|--------------------------------------------------------------------------
| OAuth & Email API Routes
|--------------------------------------------------------------------------
|
| These API routes are registered for managing OAuth2.0 authentication
| and email sending functionalities within the application.
|
*/

Route::prefix('oauth')->group(function () {
    /**
     * Route to store a new OAuth email configuration.
     * Method: POST
     * Endpoint: /api/oauth/configuration
     * Name: oauth.store-configuration
     */
    Route::post('/configuration', [OAuthEmailController::class, 'storeConfiguration'])
        ->name('oauth.store-configuration');

    /**
     * Route to get the OAuth2.0 authorization URL for a specific configuration.
     * Method: GET
     * Endpoint: /api/oauth/auth-url
     * Name: oauth.auth-url
     */
    Route::get('/auth-url', [OAuthEmailController::class, 'getAuthUrl'])
        ->name('oauth.auth-url');

    /**
     * Route to handle the OAuth2.0 callback and store the access token.
     * Method: GET
     * Endpoint: /api/oauth/callback/{uid}
     * Name: oauth.callback
     * Parameters:
     * - {uid}: The unique identifier of the configuration.
     */
    Route::get('/callback/{uid}', [OAuthEmailController::class, 'handleCallback'])
        ->name('oauth.callback');

    /**
     * Route to send an email using the configured OAuth provider.
     * Method: POST
     * Endpoint: /api/oauth/send-email
     * Name: oauth.send-email
     */
    Route::post('/send-email', [OAuthEmailController::class, 'sendEmail'])
        ->name('oauth.send-email');

    /**
     * Route to refresh an expired access token.
     * Method: POST
     * Endpoint: /api/oauth/refresh-token
     * Name: oauth.refresh-token
     */
    Route::post('/refresh-token', [OAuthEmailController::class, 'refreshToken'])
        ->name('oauth.refresh-token');

    /**
     * Route to revoke an access token.
     * Method: POST
     * Endpoint: /api/oauth/revoke-token
     * Name: oauth.revoke-token
     */
    Route::post('/revoke-token', [OAuthEmailController::class, 'revokeToken'])
        ->name('oauth.revoke-token');

    /**
     * Route to get user information from the authenticated provider.
     * Method: GET
     * Endpoint: /api/oauth/user-info
     * Name: oauth.user-info
     */
    Route::get('/user-info', [OAuthEmailController::class, 'getUserInfo'])
        ->name('oauth.user-info');
});
