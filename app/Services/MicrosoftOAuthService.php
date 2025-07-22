<?php

namespace App\Services;

use App\Contracts\OAuthServiceInterface;
use App\Exceptions\EmailException;
use App\Exceptions\OAuthException;
use App\Models\LfVendorEmailConfiguration;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Servicio para integración con Microsoft Graph API OAuth2.0
 *
 * Implementa la autenticación OAuth2.0 y operaciones de correo electrónico
 * utilizando Microsoft Graph API. Maneja tokens de acceso, refresh tokens
 * y el envío de correos electrónicos.
 */
class MicrosoftOAuthService implements OAuthServiceInterface
{

    /**
     * URL base para la autenticación OAuth2.0 de Microsoft
     */
    private const AUTHORITY = 'https://login.microsoftonline.com/';

    /**
     * URL base para la conexión al API Graph de Microsoft
     */
    private const GRAPH_API_URL = 'https://graph.microsoft.com/v1.0';

    /**
     * URL base para la autorización del API Graph de Microsoft
     */
    private const AUTHORIZE_ENDPOINT = '/oauth2/v2.0/authorize';

    /**
     * URL base para el intercambio de tokens del API Graph de Microsoft
     */
    private const TOKEN_ENDPOINT = '/oauth2/v2.0/token';

    /**
     * Configuración de Microsoft Graph
     */
    private array $config;

    /**
     * Scopes necesarios para Microsoft Graph
     */
    private array $scopes = [
        "Calendars.ReadWrite",
        "IMAP.AccessAsUser.All",
        "Mail.Read",
        "Mail.ReadWrite",
        "Mail.Send",
        "openid",
        "profile",
        "SMTP.Send",
        "User.Read",
        "email",
        "offline_access",
    ];

    /**
     * {@inheritDoc}
     */
    public function getAuthUrl(int $uid): string
    {
        $config = LfVendorEmailConfiguration::findOrFail($uid);

        if ($config->vec_provider_api !== LfVendorEmailConfiguration::PROVIDER_MICROSOFT) {
            throw OAuthException::invalidConfiguration('Configuración no es para Microsoft Graph');
        }

        $params = [
            'client_id' => $config->vec_client_id,
            'response_type' => 'code',
            'redirect_uri' => $config->vec_redirect_uri,
            'scope' => implode(' ', $this->scopes),
            'response_mode' => 'query',
            'state' => base64_encode(json_encode([
                'uid' => $uid,
                'timestamp' => time(),
                'csrf' => csrf_token()
            ])),
            'prompt' => 'consent',
            'access_type' => 'offline',
        ];

        $authUrl = self::AUTHORITY .
                   $config->vec_tenant_id .
                   self::AUTHORIZE_ENDPOINT .
                   '?' . http_build_query($params);

        Log::info('Microsoft OAuth URL generada', [
            'uid' => $uid,
            'redirect_uri' => $config->vec_redirect_uri
        ]);

        return $authUrl;
    }

    /**
     * {@inheritDoc}
     */
    public function handleCallback(string $code, ?string $state = null): array
    {

        if (empty($code)) {
            throw OAuthException::invalidAuthorizationCode();
        }

        $uid = null;
        if ($state) {
            Log::info('Procesando callback de Microsoft OAuth', [
                'state' => $state,
                'code' => $code
            ]);
            $stateData = json_decode(base64_decode($state), true);
            if (!$stateData || !isset($stateData['uid'])) {
                throw new OAuthException('Estado inválido recibido en el callback');
            }
            $uid = $stateData['uid'];
        }

        $config = LfVendorEmailConfiguration::find($uid);

        if (!$config) {
            throw OAuthException::invalidConfiguration('Configuración no encontrada');
        }

        try {
            $response = Http::asForm()->post(
                self::AUTHORITY . $config->vec_tenant_id . self::TOKEN_ENDPOINT,
                [
                    'client_id' => $config->vec_client_id,
                    'client_secret' => $config->vec_client_secret,
                    'code' => $code,
                    'redirect_uri' => $config->vec_redirect_uri,
                    'grant_type' => 'authorization_code',
                    'scope' => implode(' ', $this->scopes),
                ]
            );

            if (!$response->successful()) {
                throw new OAuthException(
                    'Error al intercambiar código por tokens',
                    $response->status(),
                    $response->json('error'),
                    $response->json('error_description')
                );
            }

            $tokenData = $response->json();
            // Obtener información del usuario
            $userInfo = $this->getUserInfo($tokenData['access_token']);

            return [
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'] ?? null,
                'expires_in' => $tokenData['expires_in'],
                'token_type' => $tokenData['token_type'] ?? 'Bearer',
                'scope' => $tokenData['scope'] ?? implode(' ', $this->scopes),
                'user_info' => $userInfo,
            ];

        } catch (Exception $e) {
            Log::error('Error en callback Microsoft OAuth', [
                'error' => $e->getMessage(),
                'code' => $code,
                'state' => $state
            ]);

            throw new OAuthException(
                'Error al procesar callback de Microsoft',
                0,
                null,
                null,
                $e
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function storeToken(LfVendorEmailConfiguration $config, array $tokenData): LfVendorEmailConfiguration
    {
        $config->update([
            'vec_access_token' => $tokenData['access_token'],
            'vec_refresh_token' => $tokenData['refresh_token'] ?? null,
            'vec_expires_in' => $tokenData['expires_in'],
            'vec_expires_at' => Carbon::now()->addSeconds($tokenData['expires_in']),
            'vec_user_email' => $tokenData['user_info']['mail'] ?? $tokenData['user_info']['userPrincipalName'],
        ]);

        Log::info('Token Microsoft almacenado', [
            'config_id' => $config->uid,
            'user_email' => $config->vec_user_email,
            'expires_at' => $config->vec_expires_at
        ]);

        return $config->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function getValidToken(LfVendorEmailConfiguration $config, ?string $email = null): LfVendorEmailConfiguration
    {
        if (!$config->vec_access_token) {
            throw OAuthException::invalidToken('Microsoft Graph');
        }

        // Si el token está expirado o próximo a expirar, intentar renovarlo
        if ($config->isTokenExpired() || $config->isTokenExpiringSoon()) {
            if (!$config->vec_refresh_token) {
                throw OAuthException::noRefreshToken('Microsoft Graph');
            }
            return $this->refreshToken($config);
        }

        return $config;
    }

    /**
     * {@inheritDoc}
     */
    public function refreshToken(LfVendorEmailConfiguration $config): LfVendorEmailConfiguration
    {
        if (!$config->vec_refresh_token) {
            throw OAuthException::noRefreshToken('Microsoft Graph');
        }

        try {
            $response = Http::asForm()->post(
                self::AUTHORITY . $config->vec_tenant_id . self::TOKEN_ENDPOINT,
                [
                    'client_id' => $config->vec_client_id,
                    'client_secret' => $config->vec_client_secret,
                    'refresh_token' => $config->vec_refresh_token,
                    'grant_type' => 'refresh_token',
                    'scope' => implode(' ', $this->scopes),
                ]
            );

            if (!$response->successful()) {
                throw new OAuthException(
                    'Error al refrescar token Microsoft',
                    $response->status(),
                    $response->json('error'),
                    $response->json('error_description')
                );
            }

            $tokenData = $response->json();

            $config->update([
                'vec_access_token' => $tokenData['access_token'],
                'vec_refresh_token' => $tokenData['refresh_token'] ?? $config->vec_refresh_token,
                'vec_expires_in' => $tokenData['expires_in'],
                'vec_expires_at' => Carbon::now()->addSeconds($tokenData['expires_in']),
            ]);

            Log::info('Token Microsoft refrescado', [
                'config_id' => $config->uid,
                'expires_at' => $config->vec_expires_at
            ]);

            return $config->fresh();

        } catch (Exception $e) {
            Log::error('Error al refrescar token Microsoft', [
                'config_id' => $config->uid,
                'error' => $e->getMessage()
            ]);

            throw new OAuthException(
                'Error al refrescar token de Microsoft',
                0,
                null,
                null,
                $e
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function sendEmail(LfVendorEmailConfiguration $config, array $emailData): bool
    {
        $this->validateEmailData($emailData);

        try {
            $config = $this->getValidToken($config);

            $message = $this->buildEmailMessage($emailData);

            $response = Http::withToken($config->vec_access_token)
                ->post(self::GRAPH_API_URL . '/me/sendMail', $message);

            if (!$response->successful()) {
                $error = $response->json('error');
                throw new EmailException(
                    'Error al enviar correo con Microsoft Graph',
                    $response->status(),
                    'send_error',
                    $emailData
                );
            }

            Log::info('Correo enviado con Microsoft Graph', [
                'config_id' => $config->uid,
                'to' => $emailData['to'],
                'subject' => $emailData['subject']
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('Error al enviar correo Microsoft', [
                'config_id' => $config->uid,
                'error' => $e->getMessage(),
                'email_data' => $emailData
            ]);

            if ($e instanceof EmailException) {
                throw $e;
            }

            throw new EmailException(
                'Error al enviar correo electrónico con Microsoft',
                0,
                'send_error',
                $emailData,
                $e
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getUserInfo(string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->get(self::GRAPH_API_URL . '/me');

            if (!$response->successful()) {
                throw new OAuthException(
                    'Error al obtener información del usuario Microsoft',
                    $response->status(),
                    $response->json('error.code'),
                    $response->json('error.message')
                );
            }

            return $response->json();

        } catch (Exception $e) {
            Log::error('Error al obtener info usuario Microsoft', [
                'error' => $e->getMessage()
            ]);

            throw new OAuthException(
                'Error al obtener información del usuario',
                0,
                null,
                null,
                $e
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getProviderName(): string
    {
        return 'Microsoft Graph';
    }

    /**
     * {@inheritDoc}
     */
    public function validateToken(string $accessToken): bool
    {
        try {
            $response = Http::withToken($accessToken)
                ->get(self::GRAPH_API_URL . '/me');

            return $response->successful();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function revokeToken(LfVendorEmailConfiguration $config): bool
    {
        try {
            // Microsoft Graph no tiene un endpoint específico para revocar tokens
            // Simplemente limpiamos los tokens de la configuración
            $config->update([
                'vec_access_token' => null,
                'vec_refresh_token' => null,
                'vec_expires_in' => null,
                'vec_expires_at' => null,
            ]);

            Log::info('Token Microsoft revocado', [
                'config_id' => $config->uid
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('Error al revocar token Microsoft', [
                'config_id' => $config->uid,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getAvailableScopes(): array
    {
        return $this->scopes;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsScope(string $scope): bool
    {
        return in_array($scope, $this->getAvailableScopes());
    }

    /**
     * Valida los datos del correo electrónico
     */
    private function validateEmailData(array $emailData): void
    {
        if (empty($emailData['to'])) {
            throw EmailException::invalidRecipient('', 'Microsoft Graph');
        }

        if (empty($emailData['subject'])) {
            throw EmailException::emptySubject('Microsoft Graph');
        }

        if (empty($emailData['content'])) {
            throw EmailException::emptyContent('Microsoft Graph');
        }

        // Validar formato de email
        if (!filter_var($emailData['to'], FILTER_VALIDATE_EMAIL)) {
            throw EmailException::invalidEmailFormat('to', $emailData['to']);
        }

        // Validar CC si existe
        if (!empty($emailData['cc'])) {
            $ccEmails = is_array($emailData['cc']) ? $emailData['cc'] : [$emailData['cc']];
            foreach ($ccEmails as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw EmailException::invalidEmailFormat('cc', $email);
                }
            }
        }
    }

    /**
     * Construye el mensaje de correo para Microsoft Graph
     */
    private function buildEmailMessage(array $emailData): array
    {
        $message = [
            'message' => [
                'subject' => $emailData['subject'],
                'body' => [
                    'contentType' => $emailData['content_type'] ?? 'HTML',
                    'content' => $emailData['content'],
                ],
                'toRecipients' => [
                    [
                        'emailAddress' => [
                            'address' => $emailData['to'],
                            'name' => $emailData['to_name'] ?? null,
                        ],
                    ],
                ],
            ],
        ];

        // Agregar CC si existe
        if (!empty($emailData['cc'])) {
            $ccEmails = is_array($emailData['cc']) ? $emailData['cc'] : [$emailData['cc']];
            $message['message']['ccRecipients'] = array_map(function ($email) {
                return [
                    'emailAddress' => [
                        'address' => $email,
                    ],
                ];
            }, $ccEmails);
        }

        // Agregar BCC si existe
        if (!empty($emailData['bcc'])) {
            $bccEmails = is_array($emailData['bcc']) ? $emailData['bcc'] : [$emailData['bcc']];
            $message['message']['bccRecipients'] = array_map(function ($email) {
                return [
                    'emailAddress' => [
                        'address' => $email,
                    ],
                ];
            }, $bccEmails);
        }

        return $message;
    }

    /**
     * {@inheritDoc}
     */
    public function storeConfiguration(array $configData): LfVendorEmailConfiguration
    {
        try {
            // Validar datos de configuración
            $this->validateConfigurationData($configData);

            // Crear nueva configuración
            $config = LfVendorEmailConfiguration::create([
                'vec_vendor_id' => $configData['vec_vendor_id'],
                'vec_location_id' => $configData['vec_location_id'],
                'vec_user_email' => $configData['vec_user_email'] ?? null,
                'vec_provider_api' => LfVendorEmailConfiguration::PROVIDER_MICROSOFT,
                'vec_client_id' => $configData['vec_client_id'],
                'vec_client_secret' => $configData['vec_client_secret'],
                'vec_redirect_uri' => $configData['vec_redirect_uri'],
                'vec_tenant_id' => $configData['vec_tenant_id'] ?? 'common',
            ]);

            Log::info('Configuración de Microsoft almacenada exitosamente', [
                'uid' => $config->uid,
                'vendor_id' => $config->vec_vendor_id,
                'location_id' => $config->vec_location_id,
                'tenant_id' => $config->vec_tenant_id,
            ]);

            return $config;

        } catch (\Exception $e) {
            Log::error('Error al almacenar configuración de Microsoft', [
                'error' => $e->getMessage(),
                'config_data' => $configData,
            ]);
            throw new OAuthException('Error al almacenar configuración de Microsoft: ' . $e->getMessage());
        }
    }

    /**
     * Valida los datos de configuración
     *
     * @param array $configData Datos de configuración a validar
     * @throws OAuthException Si los datos no son válidos
     */
    private function validateConfigurationData(array $configData): void
    {
        $requiredFields = [
            'vec_vendor_id' => 'ID del proveedor',
            'vec_location_id' => 'ID de la ubicación',
            'vec_user_email' => 'Correo electrónico del usuario',
            'vec_client_id' => 'ID del cliente',
            'vec_client_secret' => 'Secreto del cliente',
            'vec_redirect_uri' => 'URI de redirección',
        ];

        foreach ($requiredFields as $field => $label) {
            if (empty($configData[$field])) {
                throw new OAuthException("El campo {$label} es obligatorio");
            }
        }

        if (!is_int($configData['vec_vendor_id']) || $configData['vec_vendor_id'] <= 0) {
            throw new OAuthException('El ID del proveedor debe ser un entero positivo');
        }

        if (!is_int($configData['vec_location_id']) || $configData['vec_location_id'] <= 0) {
            throw new OAuthException('El ID de la ubicación debe ser un entero positivo');
        }

        if (isset($configData['vec_user_email']) && !filter_var($configData['vec_user_email'], FILTER_VALIDATE_EMAIL)) {
            throw new OAuthException('El correo electrónico del usuario no es válido');
        }

        if (!filter_var($configData['vec_redirect_uri'], FILTER_VALIDATE_URL)) {
            throw new OAuthException('La URI de redirección no es válida');
        }

        // Validación específica para Microsoft: tenant_id debe ser válido
        if (isset($configData['vec_tenant_id']) && empty($configData['vec_tenant_id'])) {
            throw new OAuthException('El tenant_id no puede estar vacío');
        }
    }
}
