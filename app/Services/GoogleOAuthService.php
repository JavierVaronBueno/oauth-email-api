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
 * OAuth2.0 Service for Google API.
 *
 * Implements the OAuthServiceInterface to provide
 * OAuth2.0 authentication and email sending functionality
 * using the Google API (Gmail).
 */
class GoogleOAuthService implements OAuthServiceInterface
{
    /**
     * Base URL for Google OAuth2.0 authentication.
     *
     * @var string
     */
    private const OAUTH_BASE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    /**
     * URL for exchanging authorization code for tokens.
     *
     * @var string
     */
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * URL for retrieving user information.
     *
     * @var string
     */
    private const USER_INFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    /**
     * URL for sending emails via Gmail API.
     *
     * @var string
     */
    private const GMAIL_SEND_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    /**
     * URL for revoking tokens.
     *
     * @var string
     */
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /**
     * Required scopes for the application.
     *
     * @var array<int, string>
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
            throw OAuthException::invalidConfiguration('Configuration not found for UID: ' . $uid);
        }

        if (!$config->isGoogleProvider()) {
            throw OAuthException::invalidConfiguration('The configuration is not for Google API');
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
                throw new OAuthException('Invalid state received in the callback.');
            }
            $uid = $stateData['uid'];
        }

        $config = LfVendorEmailConfiguration::find($uid);

        if (!$config) {
            throw OAuthException::invalidConfiguration('Configuration not found');
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
                    'Error obtaining Google token: ' . ($error['error_description'] ?? 'Unknown error'),
                    $response->status(),
                    $error['error'] ?? 'token_exchange_failed'
                );
            }

            $tokenData = $response->json();

            // Get user information
            $userInfo = $this->getUserInfo($tokenData['access_token']);
            $tokenData['user_info'] = $userInfo;

            return $tokenData;

        } catch (\Exception $e) {
            if ($e instanceof OAuthException) {
                throw $e;
            }
            throw new OAuthException('Error processing Google callback: ' . $e->getMessage());
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
                'vec_refresh_token' => $tokenData['refresh_token'] ?? $config->vec_refresh_token,
                'vec_expires_in' => $tokenData['expires_in'],
                'vec_expires_at' => Carbon::now()->addSeconds($tokenData['expires_in']),
                'vec_user_email' => $tokenData['user_info']['email'] ?? null,
            ]);

            return $config->fresh();

        } catch (\Exception $e) {
            throw new OAuthException('Error storing Google token: ' . $e->getMessage(), $e->getCode(), null, null, $e);
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

        // If the token is expired or close to expiring, attempt to refresh it
        if ($config->isTokenExpired() || $config->isTokenExpiringSoon()) {
            if (!$config->vec_refresh_token) {
                throw OAuthException::noRefreshToken('Google');
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
                    'Error refreshing Google token: ' . ($error['error_description'] ?? 'Unknown error'),
                    $response->status(),
                    $error['error'] ?? 'token_refresh_failed'
                );
            }

            $tokenData = $response->json();

            $config->update([
                'vec_access_token' => $tokenData['access_token'],
                'vec_expires_in' => $tokenData['expires_in'],
                'vec_expires_at' => Carbon::now()->addSeconds($tokenData['expires_in']),
                'vec_refresh_token' => $tokenData['refresh_token'] ?? $config->vec_refresh_token,
            ]);

            return $config->fresh();

        } catch (\Exception $e) {
            if ($e instanceof OAuthException) {
                throw $e;
            }
            throw new OAuthException('Error refreshing Google token: ' . $e->getMessage(), $e->getCode(), null, null, $e);
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
                    $error['error']['message'] ?? 'Error sending email'
                );
            }

            Log::info('Email sent successfully for Google Gmail', [
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
                throw new OAuthException(
                    'Error retrieving user information from Google: ' . ($error['error']['message'] ?? 'Unknown error'),
                    $response->status(),
                    $error['error']['code'] ?? 'user_info_failed'
                );
            }

            return $response->json();

        } catch (\Exception $e) {
            throw new OAuthException('Error retrieving user information: ' . $e->getMessage(), $e->getCode(), null, null, $e);
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
            Log::warning('Google token validation failed: ' . $e->getMessage());
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
                throw new OAuthException('No token available to revoke.');
            }

            $response = Http::post(self::REVOKE_URL, [
                'token' => $config->vec_access_token
            ]);

            if ($response->successful()) {
                // Clear tokens from the configuration upon successful revocation
                $config->update([
                    'vec_access_token' => null,
                    'vec_refresh_token' => null,
                    'vec_expires_at' => null,
                    'vec_expires_in' => 0,
                ]);

                Log::info('Google token revoked successfully for UID: ' . $config->uid);
                return true;
            }

            Log::error('Failed to revoke Google token for UID: ' . $config->uid, [
                'response_status' => $response->status(),
                'response_body' => $response->body(),
            ]);
            return false;

        } catch (\Exception $e) {
            throw new OAuthException('Error revoking Google token: ' . $e->getMessage(), $e->getCode(), null, null, $e);
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
     * Validates the provided email data.
     *
     * Ensures that required fields are present and email formats are valid.
     *
     * @param array $emailData The email data to validate.
     * @throws EmailException If the email data is invalid.
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

        // Validate CC if present
        if (!empty($emailData['cc'])) {
            $ccEmails = is_array($emailData['cc']) ? $emailData['cc'] : [$emailData['cc']];
            foreach ($ccEmails as $ccEmail) {
                if (!filter_var($ccEmail, FILTER_VALIDATE_EMAIL)) {
                    throw EmailException::invalidEmailFormat('cc', $ccEmail);
                }
            }
        }

        // Validate BCC if present
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
     * Creates the email message in RFC 2822 format.
     *
     * This format is required by the Gmail API's `messages.send` endpoint.
     *
     * @param array $emailData The email data (to, subject, content, cc, bcc).
     * @return string The formatted email message.
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

            Log::info('Google configuration stored successfully', [
                'uid' => $config->uid,
                'vendor_id' => $config->vec_vendor_id,
                'location_id' => $config->vec_location_id,
            ]);

            return $config;

        } catch (\Exception $e) {
            Log::error('Error storing Google configuration', [
                'error' => $e->getMessage(),
                'config_data' => $configData,
                'trace' => $e->getTraceAsString(),
            ]);
            throw new OAuthException('Error storing Google configuration: ' . $e->getMessage());
        }
    }

    /**
     * Validates the provided configuration data.
     *
     * Ensures that all required fields are present and correctly formatted.
     *
     * @param array $configData The configuration data to validate.
     * @throws OAuthException If the configuration data is invalid.
     */
    private function validateConfigurationData(array $configData): void
    {
        $requiredFields = [
            'vec_vendor_id' => 'Vendor ID',
            'vec_location_id' => 'Location ID',
            'vec_user_email' => 'User Email',
            'vec_client_id' => 'Client ID',
            'vec_client_secret' => 'Client Secret',
            'vec_redirect_uri' => 'Redirect URI',
        ];

        foreach ($requiredFields as $field => $label) {
            if (empty($configData[$field])) {
                throw new OAuthException("The field {$label} is required");
            }
        }

        if (!is_int($configData['vec_vendor_id']) || $configData['vec_vendor_id'] <= 0) {
            throw new OAuthException('The Vendor ID must be a positive integer');
        }

        if (!is_int($configData['vec_location_id']) || $configData['vec_location_id'] <= 0) {
            throw new OAuthException('The Location ID must be a positive integer');
        }

        if (isset($configData['vec_user_email']) && !filter_var($configData['vec_user_email'], FILTER_VALIDATE_EMAIL)) {
            throw new OAuthException('The User Email is not valid');
        }

        if (!filter_var($configData['vec_redirect_uri'], FILTER_VALIDATE_URL)) {
            throw new OAuthException('The Redirect URI is not valid');
        }
    }
}
