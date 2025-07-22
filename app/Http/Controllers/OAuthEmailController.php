<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Factories\OAuthServiceFactory;
use App\Models\LfVendorEmailConfiguration;
use App\Exceptions\OAuthException;
use App\Exceptions\EmailException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Controlador para la gestión de OAuth2.0 y envío de correos
 *
 * Maneja la autenticación OAuth2.0 y el envío de correos electrónicos
 * a través de Microsoft Graph API y Google API utilizando el patrón Factory.
 */
class OAuthEmailController extends Controller
{
    /**
     * Obtiene la URL de autorización OAuth2.0 para un proveedor específico
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAuthUrl(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'uid' => 'required|integer|exists:lf_vendor_email_configuration,uid',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => true,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $uid = $request->input('uid');
            $config = LfVendorEmailConfiguration::findOrFail($uid);

            $oauthService = OAuthServiceFactory::createFromConfig($config);
            $authUrl = $oauthService->getAuthUrl($uid);

            return response()->json([
                'success' => true,
                'data' => [
                    'auth_url' => $authUrl,
                    'provider' => $config->vec_provider_api,
                    'uid' => $uid
                ]
            ]);

        } catch (OAuthException $e) {
            Log::error('Error al obtener URL de autorización', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage()
            ]);

            return $e->render($request);

        } catch (\Exception $e) {
            Log::error('Error inesperado al obtener URL de autorización', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => true,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Maneja el callback de OAuth2.0 y almacena el token
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function handleCallback(int $uid, Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'code' => 'required|string',
                'state' => 'nullable|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => true,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $code = $request->input('code');
            $state = $request->input('state');

            $config = LfVendorEmailConfiguration::findOrFail($uid);
            $oauthService = OAuthServiceFactory::createFromConfig($config);

            // Manejar callback y obtener token
            $tokenData = $oauthService->handleCallback($code, $state);

            // Almacenar token en la base de datos
            $updatedConfig = $oauthService->storeToken($config, $tokenData);

            return response()->json([
                'success' => true,
                'message' => 'Autenticación completada exitosamente',
                'data' => [
                    'provider' => $updatedConfig->vec_provider_api,
                    'user_email' => $updatedConfig->vec_user_email,
                    'expires_at' => $updatedConfig->vec_expires_at->toISOString(),
                    'uid' => $updatedConfig->uid
                ]
            ]);

        } catch (OAuthException $e) {
            Log::error('Error en callback OAuth', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage()
            ]);

            return $e->render($request);

        } catch (\Exception $e) {
            Log::error('Error inesperado en callback OAuth', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => true,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Envía un correo electrónico utilizando el proveedor configurado
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendEmail(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'uid' => 'required|integer|exists:lf_vendor_email_configuration,uid',
                'to' => 'required|email|max:255',
                'subject' => 'required|string|max:255',
                'contentType' => 'required|string',
                'content' => 'required|string',
                'cc' => 'nullable|array',
                'cc.*' => 'email|max:255',
                'bcc' => 'nullable|array',
                'bcc.*' => 'email|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => true,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $uid = $request->input('uid');
            $config = LfVendorEmailConfiguration::findOrFail($uid);

            $oauthService = OAuthServiceFactory::createFromConfig($config);

            // Obtener token válido (renovar si es necesario)
            $validConfig = $oauthService->getValidToken($config);

            $emailData = [
                'to' => $request->input('to'),
                'subject' => $request->input('subject'),
                'contentType' => $request->input('contentType'),
                'content' => $request->input('content'),
                'cc' => $request->input('cc'),
                'bcc' => $request->input('bcc'),
            ];

            $sent = $oauthService->sendEmail($validConfig, $emailData);

            if ($sent) {
                return response()->json([
                    'success' => true,
                    'message' => 'Correo enviado exitosamente',
                    'data' => [
                        'provider' => $config->vec_provider_api,
                        'to' => $emailData['to'],
                        'subject' => $emailData['subject'],
                        'sent_at' => now()->toISOString()
                    ]
                ]);
            } else {
                throw new EmailException('Error al enviar el correo electrónico');
            }

        } catch (EmailException $e) {
            Log::error('Error al enviar correo', [
                'uid' => $request->input('uid'),
                'to' => $request->input('to'),
                'error' => $e->getMessage()
            ]);

            return $e->render($request);

        } catch (OAuthException $e) {
            Log::error('Error de OAuth al enviar correo', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage()
            ]);

            return $e->render($request);

        } catch (\Exception $e) {
            Log::error('Error inesperado al enviar correo', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => true,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Renueva un token de acceso expirado
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function refreshToken(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'uid' => 'required|integer|exists:lf_vendor_email_configuration,uid',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => true,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $uid = $request->input('uid');
            $config = LfVendorEmailConfiguration::findOrFail($uid);

            $oauthService = OAuthServiceFactory::createFromConfig($config);
            $refreshedConfig = $oauthService->refreshToken($config);

            return response()->json([
                'success' => true,
                'message' => 'Token renovado exitosamente',
                'data' => [
                    'provider' => $refreshedConfig->vec_provider_api,
                    'expires_at' => $refreshedConfig->vec_expires_at->toISOString(),
                    'uid' => $refreshedConfig->uid
                ]
            ]);

        } catch (OAuthException $e) {
            Log::error('Error al renovar token', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage()
            ]);

            return $e->render($request);

        } catch (\Exception $e) {
            Log::error('Error inesperado al renovar token', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => true,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Revoca un token de acceso
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function revokeToken(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'uid' => 'required|integer|exists:lf_vendor_email_configuration,uid',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => true,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $uid = $request->input('uid');
            $config = LfVendorEmailConfiguration::findOrFail($uid);

            $oauthService = OAuthServiceFactory::createFromConfig($config);
            $revoked = $oauthService->revokeToken($config);

            if ($revoked) {
                return response()->json([
                    'success' => true,
                    'message' => 'Token revocado exitosamente',
                    'data' => [
                        'provider' => $config->vec_provider_api,
                        'uid' => $uid
                    ]
                ]);
            } else {
                throw new OAuthException('No se pudo revocar el token');
            }

        } catch (OAuthException $e) {
            Log::error('Error al revocar token', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage()
            ]);

            return $e->render($request);

        } catch (\Exception $e) {
            Log::error('Error inesperado al revocar token', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => true,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Obtiene información del usuario autenticado
     *
     * @param Request $request La solicitud HTTP entrante
     * @return JsonResponse Respuesta en formato JSON con la información del usuario
     */
    public function getUserInfo(Request $request): JsonResponse
    {
        try {
            // Validar la entrada
            $validator = Validator::make($request->all(), [
                'uid' => 'required|integer|exists:lf_vendor_email_configuration,uid',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => true,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            // Obtener el UID y la configuración correspondiente
            $uid = $request->input('uid');
            $config = LfVendorEmailConfiguration::findOrFail($uid);

            // Crear el servicio OAuth usando la Factory
            $oauthService = OAuthServiceFactory::createFromConfig($config);

            // Obtener un token válido (refrescando si es necesario)
            $validConfig = $oauthService->getValidToken($config);

            // Obtener la información del usuario usando el token de acceso
            $userInfo = $oauthService->getUserInfo($validConfig->vec_access_token);

            // Retornar la respuesta JSON con la información del usuario
            return response()->json([
                'success' => true,
                'data' => [
                    'provider' => $config->vec_provider_api,
                    'user_info' => $userInfo,
                    'uid' => $uid
                ]
            ], 200);

        } catch (OAuthException $e) {
            // Manejar excepciones específicas de OAuth
            Log::error('Error al obtener información del usuario', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage()
            ]);

            return $e->render($request);

        } catch (\Exception $e) {
            // Manejar errores inesperados
            Log::error('Error inesperado al obtener información del usuario', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => true,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Almacena una nueva configuración para un proveedor de correo electrónico
     *
     * @param Request $request La solicitud HTTP entrante
     * @return JsonResponse Respuesta en formato JSON con la configuración creada
     */
    public function storeConfiguration(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'vec_vendor_id' => 'required|integer|min:1',
                'vec_location_id' => 'required|integer|min:1',
                'vec_user_email' => 'nullable|email|max:255',
                'vec_provider_api' => ['required', Rule::in(LfVendorEmailConfiguration::VALID_PROVIDERS)],
                'vec_client_id' => 'required|string|max:255',
                'vec_client_secret' => 'required|string|max:255',
                'vec_tenant_id' => 'nullable|string|max:255',
                'vec_redirect_uri' => 'required|url|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => true,
                    'message' => 'Datos de entrada inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $configData = $validator->validated();
            $oauthService = OAuthServiceFactory::create($configData['vec_provider_api']);
            $config = $oauthService->storeConfiguration($configData);

            return response()->json([
                'success' => true,
                'message' => 'Configuración almacenada exitosamente',
                'data' => [
                    'uid' => $config->uid,
                    'provider' => $config->vec_provider_api,
                    'vendor_id' => $config->vec_vendor_id,
                    'location_id' => $config->vec_location_id,
                    'user_email' => $config->vec_user_email,
                    'tenant_id' => $config->vec_tenant_id,
                    'created_at' => $config->TS_create->toISOString(),
                ]
            ], 201);

        } catch (OAuthException $e) {
            Log::error('Error al almacenar configuración', [
                'provider' => $request->input('vec_provider_api'),
                'error' => $e->getMessage(),
            ]);

            return $e->render($request);

        } catch (\Exception $e) {
            Log::error('Error inesperado al almacenar configuración', [
                'provider' => $request->input('vec_provider_api'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => true,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }
}
