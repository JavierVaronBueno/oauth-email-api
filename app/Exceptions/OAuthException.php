<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Base exception for OAuth2.0 errors.
 *
 * Handles errors related to OAuth2.0 authentication,
 * access tokens, refresh tokens, and authorization processes.
 */
class OAuthException extends Exception
{
    /**
     * Specific OAuth error code.
     *
     * @var string|null
     */
    protected ?string $oauthErrorCode;

    /**
     * Detailed description of the error.
     *
     * @var string|null
     */
    protected ?string $oauthErrorDescription;

    /**
     * Additional error data.
     *
     * @var array
     */
    protected array $errorData;

    /**
     * Constructor for the OAuthException.
     *
     * @param string $message The error message.
     * @param int $code The HTTP status code.
     * @param string|null $oauthErrorCode The specific OAuth error code.
     * @param string|null $oauthErrorDescription The detailed OAuth error description.
     * @param Exception|null $previous The previous exception, if any.
     * @param array $errorData Additional data related to the error.
     */
    public function __construct(
        string $message = 'OAuth2.0 authentication error',
        int $code = Response::HTTP_INTERNAL_SERVER_ERROR,
        ?string $oauthErrorCode = null,
        ?string $oauthErrorDescription = null,
        ?Exception $previous = null,
        array $errorData = []
    ) {
        parent::__construct($message, $code, $previous);

        $this->oauthErrorCode = $oauthErrorCode;
        $this->oauthErrorDescription = $oauthErrorDescription;
        $this->errorData = $errorData;
    }

    /**
     * Get the specific OAuth error code.
     *
     * @return string|null
     */
    public function getOAuthErrorCode(): ?string
    {
        return $this->oauthErrorCode;
    }

    /**
     * Get the detailed OAuth error description.
     *
     * @return string|null
     */
    public function getOAuthErrorDescription(): ?string
    {
        return $this->oauthErrorDescription;
    }

    /**
     * Get additional error data.
     *
     * @return array
     */
    public function getErrorData(): array
    {
        return $this->errorData;
    }

    /**
     * Render the exception as an HTTP response.
     *
     * This method prepares a JSON response for the client,
     * including relevant OAuth error details and logging the full error.
     *
     * @param Request $request The current HTTP request.
     * @return JsonResponse
     */
    public function render(Request $request): JsonResponse
    {
        $errorResponse = [
            'error' => true,
            'message' => $this->getMessage(),
            'error_type' => 'oauth_error',
            'http_code' => $this->getCode(),
        ];

        if ($this->oauthErrorCode) {
            $errorResponse['oauth_error_code'] = $this->oauthErrorCode;
        }

        if ($this->oauthErrorDescription) {
            $errorResponse['oauth_error_description'] = $this->oauthErrorDescription;
        }

        if (!empty($this->errorData)) {
            $errorResponse['error_data'] = $this->errorData;
        }

        // Log del error para debugging
        Log::error('OAuth Error: ' . $this->getMessage(), [
            'oauth_error_code' => $this->oauthErrorCode,
            'oauth_error_description' => $this->oauthErrorDescription,
            'error_data' => $this->errorData,
            'trace' => $this->getTraceAsString(),
        ]);

        return response()->json($errorResponse, $this->getCode());
    }

    /**
     * Create an exception for an expired access token.
     *
     * @param string $provider The OAuth provider (e.g., 'Google', 'Microsoft').
     * @return static
     */
    public static function tokenExpired(string $provider = 'Unknown'): self
    {
        return new self(
            "Access token expired for {$provider}",
            Response::HTTP_UNAUTHORIZED,
            'token_expired',
            'The access token has expired and must be refreshed'
        );
    }

    /**
     * Create an exception for an invalid access token.
     *
     * @param string $provider The OAuth provider (e.g., 'Google', 'Microsoft').
     * @return static
     */
    public static function invalidToken(string $provider = 'Unknown'): self
    {
        return new self(
            "Invalid access token for {$provider}",
            Response::HTTP_UNAUTHORIZED,
            'invalid_token',
            'The provided access token is not valid'
        );
    }

    /**
     * Create an exception for a missing refresh token.
     *
     * @param string $provider The OAuth provider (e.g., 'Google', 'Microsoft').
     * @return static
     */
    public static function noRefreshToken(string $provider = 'Unknown'): self
    {
        return new self(
            "No refresh token available for {$provider}",
            Response::HTTP_UNAUTHORIZED,
            'no_refresh_token',
            'Cannot refresh the token without a valid refresh token'
        );
    }

    /**
     * Create an exception for an invalid authorization code.
     *
     * @return static
     */
    public static function invalidAuthorizationCode(): self
    {
        return new self(
            'Invalid authorization code',
            Response::HTTP_BAD_REQUEST,
            'invalid_authorization_code',
            'The received authorization code is not valid'
        );
    }

    /**
     * Create an exception for invalid OAuth2.0 configuration.
     *
     * @param string $details Optional details about the invalid configuration.
     * @return static
     */
    public static function invalidConfiguration(string $details = ''): self
    {
        return new self(
            'Invalid OAuth2.0 configuration' . ($details ? ": {$details}" : ''),
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'invalid_configuration',
            'The OAuth2.0 client configuration is not valid'
        );
    }
}
