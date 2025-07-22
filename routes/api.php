<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OAuthEmailController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Aquí se registran las rutas de la API para la gestión de OAuth y envío de correos.
|
*/

Route::prefix('oauth')->group(function () {
    // Almacenar una nueva configuración
    Route::post('/configuration', [OAuthEmailController::class, 'storeConfiguration'])
        ->name('oauth.store-configuration');

    // Obtener la URL de autorización
    Route::get('/auth-url', [OAuthEmailController::class, 'getAuthUrl'])
        ->name('oauth.auth-url');

    // Manejar el callback de OAuth
    Route::get('/callback/{uid}', [OAuthEmailController::class, 'handleCallback'])
        ->name('oauth.callback');

    // Enviar un correo electrónico
    Route::post('/send-email', [OAuthEmailController::class, 'sendEmail'])
        ->name('oauth.send-email');

    // Refrescar un token de acceso
    Route::post('/refresh-token', [OAuthEmailController::class, 'refreshToken'])
        ->name('oauth.refresh-token');

    // Revocar un token de acceso
    Route::post('/revoke-token', [OAuthEmailController::class, 'revokeToken'])
        ->name('oauth.revoke-token');

    // Obtener información del usuario autenticado
    Route::get('/user-info', [OAuthEmailController::class, 'getUserInfo'])
        ->name('oauth.user-info');
});
