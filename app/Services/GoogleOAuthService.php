<?php

namespace App\Services;

use App\Contracts\OAuthServiceInterface;
use App\Models\LfVendorEmailConfiguration;
use App\Exceptions\OAuthException;
use App\Exceptions\EmailException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Servicio de OAuth2.0 para Google API
 *
 * Implementa la interfaz OAuthServiceInterface para proporcionar
 * funcionalidad de autenticación OAuth2.0 y envío de correos
 * electrónicos utilizando Google API (Gmail).
 */
class GoogleOAuthService implements OAuthServiceInterface
{
    /**
     * URL base para la autenticación OAuth2.0 de Google
     */
    private const OAUTH_BASE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    /**
     * URL para intercambiar código por token
     */
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * URL para obtener información del usuario
     */
    private const USER_INFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    /**
     * URL para enviar correos electrónicos
     */
    private const GMAIL_SEND_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    /**
     * URL para revocar tokens
     */
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /**
     * Scopes requeridos para la aplicación
     */
    private array $scopes = [
        'https://www.googleapis.com/auth/gmail.send',
        'https://www.googleapis.com/auth/userinfo.email',
        'https://www.googleapis.com/auth/userinfo.profile'
    ];

    /**
     * {@inheritDoc}
     */
    public function getAuthUrl(int $uid): string
    {
        $config = LfVendorEmailConfiguration::find($uid);

        if (!$config) {
            throw OAuthException::invalidConfiguration('Configuración no encontrada para UID: ' . $uid);
        }

        if (!$config->isGoogleProvider()) {
            throw OAuthException::invalidConfiguration('La configuración no es para Google API');
        }

        $params = [
            'client_id' => $config->vec_client_id,
            'redirect_uri' => $config->vec_redirect_uri,
            'scope' => implode(' ', $this->scopes),
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => base64_encode(json_encode(['uid' => $uid, 'timestamp' => time()])),
        ];

        return self::OAUTH_BASE_URL . '?' . http_build_query($params);
    }

    /**
     * {@inheritDoc}
     */
    public function handleCallback(string $code, ?string $state = null): array
    {
        if (!$code) {
            throw OAuthException::invalidAuthorizationCode();
        }

        $uid = null;
        if ($state) {
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
            $response = Http::post(self::TOKEN_URL, [
                'client_id' => $config->vec_client_id,
                'client_secret' => $config->vec_client_secret,
                'redirect_uri' => $config->vec_redirect_uri,
                'grant_type' => 'authorization_code',
                'code' => $code,
            ]);

            if (!$response->successful()) {
                $error = $response->json();
                throw new OAuthException(
                    'Error al obtener token de Google: ' . ($error['error_description'] ?? 'Error desconocido'),
                    $response->status(),
                    $error['error'] ?? 'token_exchange_failed'
                );
            }

            $tokenData = $response->json();

            // Obtener información del usuario
            $userInfo = $this->getUserInfo($tokenData['access_token']);
            $tokenData['user_info'] = $userInfo;

            return $tokenData;

        } catch (\Exception $e) {
            if ($e instanceof OAuthException) {
                throw $e;
            }
            throw new OAuthException('Error al procesar callback de Google: ' . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function storeToken(LfVendorEmailConfiguration $config, array $tokenData): LfVendorEmailConfiguration
    {
        try {
            $config->update([
                'vec_access_token' => $tokenData['access_token'],
                'vec_refresh_token' => $tokenData['refresh_token'] ?? null,
                'vec_expires_in' => $tokenData['expires_in'],
                'vec_expires_at' => Carbon::now()->addSeconds($tokenData['expires_in']),
                'vec_user_email' => $tokenData['user_info']['email'] ?? null,
            ]);

            return $config->fresh();

        } catch (\Exception $e) {
            throw new OAuthException('Error al almacenar token de Google: ' . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getValidToken(LfVendorEmailConfiguration $config, ?string $email = null): LfVendorEmailConfiguration
    {
        if (!$config->vec_access_token) {
            throw OAuthException::invalidToken('Google');
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
            throw OAuthException::noRefreshToken('Google');
        }

        try {
            $response = Http::post(self::TOKEN_URL, [
                'client_id' => $config->vec_client_id,
                'client_secret' => $config->vec_client_secret,
                'refresh_token' => $config->vec_refresh_token,
                'grant_type' => 'refresh_token',
            ]);

            if (!$response->successful()) {
                $error = $response->json();
                throw new OAuthException(
                    'Error al renovar token de Google: ' . ($error['error_description'] ?? 'Error desconocido'),
                    $response->status(),
                    $error['error'] ?? 'token_refresh_failed'
                );
            }

            $tokenData = $response->json();

            $config->update([
                'vec_access_token' => $tokenData['access_token'],
                'vec_expires_in' => $tokenData['expires_in'],
                'vec_expires_at' => Carbon::now()->addSeconds($tokenData['expires_in']),
                // Google no siempre devuelve un nuevo refresh_token
                'vec_refresh_token' => $tokenData['refresh_token'] ?? $config->vec_refresh_token,
            ]);

            return $config->fresh();

        } catch (\Exception $e) {
            if ($e instanceof OAuthException) {
                throw $e;
            }
            throw new OAuthException('Error al renovar token de Google: ' . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function sendEmail(LfVendorEmailConfiguration $config, array $emailData): bool
    {
        $this->validateEmailData($emailData);

        try {
            $message = $this->createEmailMessage($emailData);

            $response = Http::withToken($config->vec_access_token)
                ->post(self::GMAIL_SEND_URL, [
                    'raw' => base64_encode($message)
                ]);

            if (!$response->successful()) {
                $error = $response->json();
                throw EmailException::networkError(
                    'Google Gmail',
                    $error['error']['message'] ?? 'Error al enviar correo'
                );
            }

            Log::info('Correo enviado exitosamente mediante Google Gmail', [
                'to' => $emailData['to'],
                'subject' => $emailData['subject'],
                'message_id' => $response->json()['id'] ?? null
            ]);

            return true;

        } catch (\Exception $e) {
            if ($e instanceof EmailException) {
                throw $e;
            }
            throw EmailException::networkError('Google Gmail', $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getUserInfo(string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->get(self::USER_INFO_URL);

            if (!$response->successful()) {
                throw new OAuthException('Error al obtener información del usuario de Google');
            }

            return $response->json();

        } catch (\Exception $e) {
            throw new OAuthException('Error al obtener información del usuario: ' . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getProviderName(): string
    {
        return 'Google';
    }

    /**
     * {@inheritDoc}
     */
    public function validateToken(string $accessToken): bool
    {
        try {
            $response = Http::withToken($accessToken)
                ->get(self::USER_INFO_URL);

            return $response->successful();

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function revokeToken(LfVendorEmailConfiguration $config): bool
    {
        try {
            if (!$config->vec_access_token) {
                throw new OAuthException('No hay token para revocar');
            }

            $response = Http::post(self::REVOKE_URL, [
                'token' => $config->vec_access_token
            ]);

            if ($response->successful()) {
                // Limpiar tokens de la configuración
                $config->update([
                    'vec_access_token' => null,
                    'vec_refresh_token' => null,
                    'vec_expires_at' => null,
                    'vec_expires_in' => 0,
                ]);

                return true;
            }

            return false;

        } catch (\Exception $e) {
            throw new OAuthException('Error al revocar token de Google: ' . $e->getMessage());
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
        return in_array($scope, $this->scopes);
    }

    /**
     * Valida los datos del correo electrónico
     *
     * @param array $emailData Datos del correo a validar
     * @throws EmailException Si los datos no son válidos
     */
    private function validateEmailData(array $emailData): void
    {
        if (empty($emailData['to'])) {
            throw EmailException::invalidRecipient('', 'Google Gmail');
        }

        if (!filter_var($emailData['to'], FILTER_VALIDATE_EMAIL)) {
            throw EmailException::invalidRecipient($emailData['to'], 'Google Gmail');
        }

        if (empty($emailData['subject'])) {
            throw EmailException::emptySubject('Google Gmail');
        }

        if (empty($emailData['content'])) {
            throw EmailException::emptyContent('Google Gmail');
        }

        // Validar CC si existe
        if (!empty($emailData['cc'])) {
            $ccEmails = is_array($emailData['cc']) ? $emailData['cc'] : [$emailData['cc']];
            foreach ($ccEmails as $ccEmail) {
                if (!filter_var($ccEmail, FILTER_VALIDATE_EMAIL)) {
                    throw EmailException::invalidEmailFormat('cc', $ccEmail);
                }
            }
        }

        // Validar BCC si existe
        if (!empty($emailData['bcc'])) {
            $bccEmails = is_array($emailData['bcc']) ? $emailData['bcc'] : [$emailData['bcc']];
            foreach ($bccEmails as $bccEmail) {
                if (!filter_var($bccEmail, FILTER_VALIDATE_EMAIL)) {
                    throw EmailException::invalidEmailFormat('bcc', $bccEmail);
                }
            }
        }
    }

    /**
     * Crea el mensaje de correo electrónico en formato RFC 2822
     *
     * @param array $emailData Datos del correo electrónico
     * @return string Mensaje formateado
     */
    private function createEmailMessage(array $emailData): string
    {
        $message = "To: {$emailData['to']}\r\n";
        $message .= "Subject: {$emailData['subject']}\r\n";

        if (!empty($emailData['cc'])) {
            $cc = is_array($emailData['cc']) ? implode(', ', $emailData['cc']) : $emailData['cc'];
            $message .= "Cc: {$cc}\r\n";
        }

        if (!empty($emailData['bcc'])) {
            $bcc = is_array($emailData['bcc']) ? implode(', ', $emailData['bcc']) : $emailData['bcc'];
            $message .= "Bcc: {$bcc}\r\n";
        }

        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $message .= quoted_printable_encode($emailData['content']) . "\r\n";

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
                'vec_provider_api' => LfVendorEmailConfiguration::PROVIDER_GOOGLE,
                'vec_client_id' => $configData['vec_client_id'],
                'vec_client_secret' => $configData['vec_client_secret'],
                'vec_redirect_uri' => $configData['vec_redirect_uri']
            ]);

            Log::info('Configuración de Google almacenada exitosamente', [
                'uid' => $config->uid,
                'vendor_id' => $config->vec_vendor_id,
                'location_id' => $config->vec_location_id,
            ]);

            return $config;

        } catch (\Exception $e) {
            Log::error('Error al almacenar configuración de Google', [
                'error' => $e->getMessage(),
                'config_data' => $configData,
            ]);
            throw new OAuthException('Error al almacenar configuración de Google: ' . $e->getMessage());
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
    }
}
