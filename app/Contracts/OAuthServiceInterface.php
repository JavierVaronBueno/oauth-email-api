<?php

namespace App\Contracts;

use App\Models\LfVendorEmailConfiguration;

/**
 * Interface for OAuth2.0 services.
 *
 * Defines the common contract for all OAuth2.0 authentication services
 * with email providers (Microsoft Graph and Google API).
 *
 * This interface ensures that all implementing services adhere to the same
 * basic OAuth2.0 operations and email sending functionalities.
 */
interface OAuthServiceInterface
{
    /**
     * Retrieves the authentication URL for the API provider.
     *
     * This URL is used to redirect the user to the OAuth provider's authorization page.
     *
     * @param int $uid The unique identifier of the configuration provider.
     * @return string The OAuth2.0 authorization URL.
     * @throws OAuthException If the configuration is invalid or cannot be retrieved.
     */
    public function getAuthUrl(int $uid): string;

    /**
     * Handles the authentication callback and obtains the access token.
     *
     * This method processes the authorization code received from the OAuth provider
     * and exchanges it for access and refresh tokens.
     *
     * @param string $code The authorization code received after successful authentication.
     * @param string|null $state An optional state parameter for authentication verification (CSRF protection).
     * @return array An associative array containing token data (e.g., 'access_token', 'refresh_token', 'expires_in').
     * @throws OAuthException If the callback fails or token exchange is unsuccessful.
     */
    public function handleCallback(string $code, ?string $state = null): array;

    /**
     * Stores the access token data in the database.
     *
     * This method persists the obtained access and refresh tokens associated with
     * a specific vendor email configuration.
     *
     * @param LfVendorEmailConfiguration $config The vendor email configuration model.
     * @param array $tokenData The token data (e.g., 'access_token', 'refresh_token', 'expires_in').
     * @return LfVendorEmailConfiguration The updated vendor email configuration model.
     * @throws OAuthException If the token cannot be stored due to database or data issues.
     */
    public function storeToken(LfVendorEmailConfiguration $config, array $tokenData): LfVendorEmailConfiguration;

    /**
     * Retrieves a valid access token, refreshing it if it has expired.
     *
     * This method ensures that an active and valid token is always returned,
     * handling token refreshing automatically when necessary.
     *
     * @param LfVendorEmailConfiguration $config The vendor email configuration.
     * @param string|null $email Optional email address to retrieve a token associated with a specific user (if applicable).
     * @return LfVendorEmailConfiguration The configuration model with the valid token.
     * @throws OAuthException If no valid token is available or cannot be refreshed.
     */
    public function getValidToken(LfVendorEmailConfiguration $config, ?string $email = null): LfVendorEmailConfiguration;

    /**
     * Refreshes an expired access token using the refresh token.
     *
     * This method communicates with the OAuth provider to obtain a new access token
     * without requiring user re-authentication.
     *
     * @param LfVendorEmailConfiguration $config The configuration containing the token to be refreshed.
     * @return LfVendorEmailConfiguration The updated configuration model with the new access token.
     * @throws OAuthException If the token cannot be refreshed.
     */
    public function refreshToken(LfVendorEmailConfiguration $config): LfVendorEmailConfiguration;

    /**
     * Sends an email through the API provider.
     *
     * This method uses the configured OAuth service to dispatch an email.
     *
     * @param LfVendorEmailConfiguration $config The configuration with a valid access token.
     * @param array $emailData An associative array containing email details (e.g., 'to', 'subject', 'body', 'attachments').
     * @return bool True if the email was sent successfully, false otherwise.
     * @throws EmailException If the email cannot be sent due to provider errors or invalid data.
     */
    public function sendEmail(LfVendorEmailConfiguration $config, array $emailData): bool;

    /**
     * Retrieves user information using the provided access token.
     *
     * This method typically makes a call to the OAuth provider's user info endpoint.
     *
     * @param string $accessToken The access token to authenticate the user info request.
     * @return array An associative array containing user information (e.g., 'id', 'email', 'name').
     * @throws OAuthException If user information cannot be retrieved (e.g., invalid token, API error).
     */
    public function getUserInfo(string $accessToken): array;

    /**
     * Retrieves the name of the API provider (e.g., 'Microsoft' or 'Google').
     *
     * @return string The name of the provider.
     */
    public function getProviderName(): string;

    /**
     * Validates an access token by making a test request to the provider.
     *
     * This method can be used to verify the active status and validity of a token.
     *
     * @param string $accessToken The access token to validate.
     * @return bool True if the token is valid, false otherwise.
     */
    public function validateToken(string $accessToken): bool;

    /**
     * Revokes the access token with the OAuth provider.
     *
     * This method invalidates the token, preventing further use.
     *
     * @param LfVendorEmailConfiguration $config The configuration containing the token to revoke.
     * @return bool True if the token was successfully revoked, false otherwise.
     * @throws OAuthException If the token cannot be revoked.
     */
    public function revokeToken(LfVendorEmailConfiguration $config): bool;

    /**
     * Retrieves the available scopes (permissions) for the provider.
     *
     * @return array<int, string> A list of available scopes.
     */
    public function getAvailableScopes(): array;

    /**
     * Checks if the provider supports a specific scope.
     *
     * @param string $scope The scope to check for support.
     * @return bool True if the scope is supported, false otherwise.
     */
    public function supportsScope(string $scope): bool;

    /**
     * Stores a new email provider configuration.
     *
     * This method persists the initial configuration details for a new OAuth provider.
     *
     * @param array $configData An associative array of configuration data (e.g., 'vec_vendor_id', 'vec_location_id', 'client_id', 'client_secret').
     * @return LfVendorEmailConfiguration The newly created configuration model.
     * @throws OAuthException If the configuration cannot be stored.
     */
    public function storeConfiguration(array $configData): LfVendorEmailConfiguration;
}
