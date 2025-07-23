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
 * Service for integration with Microsoft Graph API OAuth2.0.
 *
 * Implements OAuth2.0 authentication and email operations
 * using the Microsoft Graph API. Handles access tokens, refresh tokens,
 * and email sending.
 */
class MicrosoftOAuthService implements OAuthServiceInterface
{

    /**
     * Base URL for Microsoft OAuth2.0 authentication.
     *
     * @var string
     */
    private const AUTHORITY = 'https://login.microsoftonline.com/';

    /**
     * Base URL for connecting to the Microsoft Graph API.
     *
     * @var string
     */
    private const GRAPH_API_URL = 'https://graph.microsoft.com/v1.0';

    /**
     * Endpoint for Microsoft Graph API authorization.
     *
     * @var string
     */
    private const AUTHORIZE_ENDPOINT = '/oauth2/v2.0/authorize';

    /**
     * Endpoint for Microsoft Graph API token exchange.
     *
     * @var string
     */
    private const TOKEN_ENDPOINT = '/oauth2/v2.0/token';

    /**
     * Required scopes for Microsoft Graph API access.
     *
     * @var array<int, string>
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
            throw OAuthException::invalidConfiguration('Configuration is not for Microsoft Graph.');
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
            'redirect_uri' => $config->vec_redirect_uri,
            'tenant_id' => $config->vec_tenant_id
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
            Log::info('Processing Microsoft OAuth callback', [
                'state' => $state,
                'code' => $code
            ]);
            $stateData = json_decode(base64_decode($state), true);
            if (!$stateData || !isset($stateData['uid'])) {
                throw new OAuthException('Invalid state received in the callback. Missing UID or malformed data.');
            }
            $uid = $stateData['uid'];
        }

        $config = LfVendorEmailConfiguration::find($uid);

        if (!$config) {
            throw OAuthException::invalidConfiguration('Configuration not found for callback processing.');
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
                    'Microsoft token exchange failed',
                    $response->status(),
                    $response->json('error'),
                    $response->json('error_description')
                );
            }

            $tokenData = $response->json();
            // Retrieve user information using the obtained access token
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
            Log::error('Error in Microsoft OAuth callback processing', [
                'error_message' => $e->getMessage(),
                'code_received' => $code,
                'state_received' => $state,
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw if it's already an OAuthException, otherwise wrap it
            if ($e instanceof OAuthException) {
                throw $e;
            }
            throw new OAuthException(
                'Error processing Microsoft callback: ' . $e->getMessage(),
                $e->getCode(),
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
        try {
            $config->update([
                'vec_access_token' => $tokenData['access_token'],
                'vec_refresh_token' => $tokenData['refresh_token'] ?? null,
                'vec_expires_in' => $tokenData['expires_in'],
                'vec_expires_at' => Carbon::now()->addSeconds($tokenData['expires_in']),
                'vec_user_email' => $tokenData['user_info']['mail'] ?? $tokenData['user_info']['userPrincipalName'],
            ]);

            Log::info('Microsoft token stored successfully', [
                'config_id' => $config->uid,
                'user_email' => $config->vec_user_email,
                'expires_at' => $config->vec_expires_at->toDateTimeString()
            ]);

            return $config->fresh();
        } catch (Exception $e) {
            Log::error('Error storing Microsoft token', [
                'config_id' => $config->uid,
                'error_message' => $e->getMessage(),
                'token_data_keys' => array_keys($tokenData), // Log keys to avoid sensitive data
                'trace' => $e->getTraceAsString(),
            ]);
            throw new OAuthException('Error storing Microsoft token: ' . $e->getMessage(), $e->getCode(), null, null, $e);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getValidToken(LfVendorEmailConfiguration $config, ?string $email = null): LfVendorEmailConfiguration
    {
        if (!$config->vec_access_token) {
            throw OAuthException::invalidToken('Microsoft Graph');
        }

        // If the token is expired or close to expiring, attempt to refresh it
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
                    'Error refreshing Microsoft token:' . $response->json('error_description', 'Unknown error'),
                    $response->status(),
                    $response->json('error')  ?? 'token_refresh_failed',
                    $response->json('error_description')  ?? null
                );
            }

            $tokenData = $response->json();

            $config->update([
                'vec_access_token' => $tokenData['access_token'],
                'vec_refresh_token' => $tokenData['refresh_token'] ?? $config->vec_refresh_token,
                'vec_expires_in' => $tokenData['expires_in'],
                'vec_expires_at' => Carbon::now()->addSeconds($tokenData['expires_in']),
            ]);

            Log::info('Microsoft token refreshed successfully', [
                'config_id' => $config->uid,
                'expires_at' => $config->vec_expires_at->toDateTimeString()
            ]);

            return $config->fresh();

        } catch (Exception $e) {
            Log::error('Error refreshing Microsoft token', [
                'config_id' => $config->uid,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw if it's already an OAuthException, otherwise wrap it
            if ($e instanceof OAuthException) {
                throw $e;
            }
            throw new OAuthException(
                'Error refreshing Microsoft token: ' . $e->getMessage(),
                $e->getCode(),
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
            // Ensure the token is valid before attempting to send email
            $config = $this->getValidToken($config);

            $message = $this->buildEmailMessage($emailData);

            $response = Http::withToken($config->vec_access_token)
                ->post(self::GRAPH_API_URL . '/me/sendMail', $message);

            if (!$response->successful()) {
                $error = $response->json('error');
                throw new EmailException(
                    'Error sending email with Microsoft Graph: ' . ($error['message'] ?? 'Unknown error'),
                    $response->status(),
                    'send_error',
                    $emailData
                );
            }

            Log::info('Email sent successfully with Microsoft Graph', [
                'config_id' => $config->uid,
                'to' => $emailData['to'],
                'subject' => $emailData['subject']
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('General error sending Microsoft email', [
                'config_id' => $config->uid,
                'error_message' => $e->getMessage(),
                'email_data' => $emailData,
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw if it's already an EmailException, otherwise wrap it
            if ($e instanceof EmailException) {
                throw $e;
            }

            throw new EmailException(
                'Error sending email with Microsoft: ' . $e->getMessage(),
                $e->getCode(),
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
                    'Error retrieving Microsoft user information: ' . $response->json('error.message', 'Unknown error'),
                    $response->status(),
                    $response->json('error.code')  ?? 'user_info_failed',
                    $response->json('error.message') ?? null
                );
            }

            return $response->json();

        } catch (Exception $e) {
            Log::error('Error retrieving Microsoft user info', [
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new OAuthException(
                'Error retrieving user information: ' . $e->getMessage(),
                $e->getCode(),
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
            Log::warning('Microsoft token validation failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function revokeToken(LfVendorEmailConfiguration $config): bool
    {
        try {
            // Microsoft Graph does not have a specific public endpoint to revoke tokens
            // directly via an API call for user-delegated permissions.
            // The common approach is to clear the tokens from the application's side.
            $config->update([
                'vec_access_token' => null,
                'vec_refresh_token' => null,
                'vec_expires_in' => null,
                'vec_expires_at' => null,
            ]);

            Log::info('Microsoft token cleared from configuration (simulated revocation)', [
                'config_id' => $config->uid
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('Error clearing Microsoft token from configuration', [
                'config_id' => $config->uid,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
     * Validates the email data array before sending.
     *
     * Ensures required fields are present and email addresses are in a valid format.
     *
     * @param array $emailData The email data array to validate.
     * @throws EmailException If any email data is invalid.
     * @return void
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

        // Validate 'to' email format
        if (!filter_var($emailData['to'], FILTER_VALIDATE_EMAIL)) {
            throw EmailException::invalidEmailFormat('to', $emailData['to']);
        }

        // Validate CC emails if present
        if (!empty($emailData['cc'])) {
            $ccEmails = is_array($emailData['cc']) ? $emailData['cc'] : [$emailData['cc']];
            foreach ($ccEmails as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw EmailException::invalidEmailFormat('cc', $email);
                }
            }
        }

        // Validate BCC emails if present
        if (!empty($emailData['bcc'])) {
            $bccEmails = is_array($emailData['bcc']) ? $emailData['bcc'] : [$emailData['bcc']];
            foreach ($bccEmails as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw EmailException::invalidEmailFormat('bcc', $email);
                }
            }
        }
    }

    /**
     * Builds the email message payload for Microsoft Graph API.
     *
     * This method constructs the array structure required by the `/me/sendMail` endpoint.
     *
     * @param array $emailData The email data (to, subject, content, cc, bcc, content_type, to_name).
     * @return array<string, mixed> The formatted email message payload.
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
            'saveToSentItems' => true, // Default to saving to sent items
        ];

        // Add CC recipients if present
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

        // Add BCC recipients if present
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
            // Validate configuration data before creation
            $this->validateConfigurationData($configData);

            // Create new configuration
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

            Log::info('Microsoft configuration stored successfully', [
                'uid' => $config->uid,
                'vendor_id' => $config->vec_vendor_id,
                'location_id' => $config->vec_location_id,
                'tenant_id' => $config->vec_tenant_id,
            ]);

            return $config;

        } catch (\Exception $e) {
            Log::error('Error storing Microsoft configuration', [
                'error_message' => $e->getMessage(),
                'config_data' => $configData,
                'trace' => $e->getTraceAsString(),
            ]);
            throw new OAuthException('Error storing Microsoft configuration: ' . $e->getMessage());
        }
    }

    /**
     * Validates the provided configuration data for Microsoft Graph.
     *
     * Ensures that all required fields are present and correctly formatted.
     *
     * @param array $configData The configuration data to validate.
     * @throws OAuthException If the configuration data is invalid.
     *
     * @return void
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
                throw new OAuthException("The field {$label} is required");
            }
        }

        if (!is_int($configData['vec_vendor_id']) || $configData['vec_vendor_id'] <= 0) {
            throw new OAuthException('The vendor ID must be a positive integer');
        }

        if (!is_int($configData['vec_location_id']) || $configData['vec_location_id'] <= 0) {
            throw new OAuthException('The location ID must be a positive integer');
        }

        if (isset($configData['vec_user_email']) && !filter_var($configData['vec_user_email'], FILTER_VALIDATE_EMAIL)) {
            throw new OAuthException('The user email is not valid');
        }

        if (!filter_var($configData['vec_redirect_uri'], FILTER_VALIDATE_URL)) {
            throw new OAuthException('The redirect URI is not valid');
        }

        // Specific validation for Microsoft: tenant_id must be valid
        if (isset($configData['vec_tenant_id']) && empty($configData['vec_tenant_id'])) {
            throw new OAuthException('The Tenant ID cannot be empty if provided.');
        }
    }
}
