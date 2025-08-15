<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Factories\OAuthServiceFactory;
use App\Models\LfVendorEmailConfiguration;
use App\Exceptions\OAuthException;
use App\Exceptions\EmailException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Controller for OAuth2.0 management and email sending.
 *
 * Handles OAuth2.0 authentication and email dispatch
 * through Microsoft Graph API and Google API using the Factory pattern.
 */
class OAuthEmailController extends Controller
{
    /**
     * Retrieves the OAuth2.0 authorization URL for a specific provider configuration.
     *
     * @param Request $request The incoming HTTP request, expecting 'uid'.
     * @return JsonResponse A JSON response containing the authorization URL or an error.
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
                    'message' => 'Invalid input data',
                    'errors' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
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
            ], Response::HTTP_OK);

        } catch (OAuthException $e) {
            Log::error('Error getting authorization URL', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage()
            ]);

            return $e->render($request);

        } catch (\Exception $e) {
            Log::error('Unexpected error getting authorization URL', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => true,
                'message' => 'Internal server errorr'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Handles the OAuth2.0 callback, exchanges the authorization code for tokens, and stores them.
     *
     * @param int $uid The unique identifier of the configuration provider (from route parameter).
     * @param Request $request The incoming HTTP request, expecting 'code' and optionally 'state'.
     * @return JsonResponse A JSON response indicating success or failure of the authentication process.
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
                    'message' => 'Invalid input data',
                    'errors' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            $code = $request->input('code');
            $state = $request->input('state');

            $config = LfVendorEmailConfiguration::findOrFail($uid);
            $oauthService = OAuthServiceFactory::createFromConfig($config);

            // Handle callback and obtain token data
            $tokenData = $oauthService->handleCallback($code, $state);

            // Store token in the database
            $updatedConfig = $oauthService->storeToken($config, $tokenData);

            return response()->json([
                'success' => true,
                'message' => 'Authentication completed successfully',
                'data' => [
                    'provider' => $updatedConfig->vec_provider_api,
                    'user_email' => $updatedConfig->vec_user_email,
                    'expires_at' => $updatedConfig->vec_expires_at->toISOString(),
                    'uid' => $updatedConfig->uid
                ]
            ], Response::HTTP_OK);

        } catch (OAuthException $e) {
            Log::error('OAuth callback error', [
                'uid' => $uid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $e->render($request);

        } catch (\Exception $e) {
            Log::error('Unexpected error in OAuth callback', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => true,
                'message' => 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Sends an email using the configured provider.
     *
     * @param Request $request The incoming HTTP request, expecting email details and 'uid'.
     * @return JsonResponse A JSON response indicating the email sending status.
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
                'replyTo' => 'nullable|email|max:255',
                'attachments' => 'nullable|array',
                // Validation: max 5 files, 10MB each, common document/image types
                'attachments.*' => 'file|max:10240|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,gif',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => true,
                    'message' => 'Invalid input data',
                    'errors' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            $uid = $request->input('uid');
            $config = LfVendorEmailConfiguration::findOrFail($uid);

            $oauthService = OAuthServiceFactory::createFromConfig($config);

            // Get a valid token (refreshing if necessary)
            $validConfig = $oauthService->getValidToken($config);

            $emailData = [
                'to' => $request->input('to'),
                'subject' => $request->input('subject'),
                'contentType' => $request->input('contentType'),
                'content' => $request->input('content'),
                'cc' => $request->input('cc'),
                'bcc' => $request->input('bcc'),
                'replyTo' => $request->input('replyTo'),
            ];

            // Handle file attachments if they exist
            if ($request->hasFile('attachments')) {
                $attachments = [];
                foreach ($request->file('attachments') as $file) {
                    $attachments[] = [
                        'path' => $file->getRealPath(),
                        'name' => $file->getClientOriginalName(),
                        'mimeType' => $file->getClientMimeType(),
                    ];
                }
                $emailData['attachments'] = $attachments;
            }

            $sent = $oauthService->sendEmail($validConfig, $emailData);

            if ($sent) {
                return response()->json([
                    'success' => true,
                    'message' => 'Email sent successfully',
                    'data' => [
                        'provider' => $config->vec_provider_api,
                        'to' => $emailData['to'],
                        'subject' => $emailData['subject'],
                        'sent_at' => now()->toISOString()
                    ]
                ], Response::HTTP_OK);
            } else {
                throw new EmailException('Failed to send email.');
            }

        } catch (EmailException $e) {
            Log::error('Error sending email', [
                'uid' => $request->input('uid'),
                'to' => $request->input('to'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $e->render($request);

        } catch (OAuthException $e) {
            Log::error('OAuth error when sending email', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $e->render($request);

        } catch (\Exception $e) {
            Log::error('Unexpected error sending email', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => true,
                'message' => 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Refreshes an expired access token for a given configuration.
     *
     * @param Request $request The incoming HTTP request, expecting 'uid'.
     * @return JsonResponse A JSON response indicating the token refresh status.
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
                    'message' => 'Invalid input data',
                    'errors' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            $uid = $request->input('uid');
            $config = LfVendorEmailConfiguration::findOrFail($uid);

            $oauthService = OAuthServiceFactory::createFromConfig($config);
            $refreshedConfig = $oauthService->refreshToken($config);

            return response()->json([
                'success' => true,
                'message' => 'Token refreshed successfully',
                'data' => [
                    'provider' => $refreshedConfig->vec_provider_api,
                    'expires_at' => $refreshedConfig->vec_expires_at->toISOString(),
                    'uid' => $refreshedConfig->uid
                ]
            ], Response::HTTP_OK);

        } catch (OAuthException $e) {
            Log::error('Error refreshing token', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $e->render($request);

        } catch (\Exception $e) {
            Log::error('Unexpected error refreshing token', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => true,
                'message' => 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Revokes an access token for a given configuration.
     *
     * @param Request $request The incoming HTTP request, expecting 'uid'.
     * @return JsonResponse A JSON response indicating the token revocation status.
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
                    'message' => 'Invalid input data',
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
                    'message' => 'Token revoked successfully',
                    'data' => [
                        'provider' => $config->vec_provider_api,
                        'uid' => $uid
                    ]
                ], Response::HTTP_OK);
            } else {
                throw new OAuthException('Failed to revoke token');
            }

        } catch (OAuthException $e) {
            Log::error('Error revoking token', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $e->render($request);

        } catch (\Exception $e) {
            Log::error('Unexpected error revoking token', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => true,
                'message' => 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Retrieves authenticated user information for a given configuration.
     *
     * @param Request $request The incoming HTTP request, expecting 'uid'.
     * @return JsonResponse A JSON response containing the user information.
     */
    public function getUserInfo(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'uid' => 'required|integer|exists:lf_vendor_email_configuration,uid',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => true,
                    'message' => 'Invalid input data',
                    'errors' => $validator->errors()
                ], 400);
            }

            // Get UID and corresponding configuration
            $uid = $request->input('uid');
            $config = LfVendorEmailConfiguration::findOrFail($uid);

            // Create OAuth service using the Factory
            $oauthService = OAuthServiceFactory::createFromConfig($config);

            // Get a valid token (refreshing if necessary)
            $validConfig = $oauthService->getValidToken($config);

            // Get user information using the access token
            $userInfo = $oauthService->getUserInfo($validConfig->vec_access_token);

            // Return JSON response with user information
            return response()->json([
                'success' => true,
                'data' => [
                    'provider' => $config->vec_provider_api,
                    'user_info' => $userInfo,
                    'uid' => $uid
                ]
            ], Response::HTTP_OK);

        } catch (OAuthException $e) {
            Log::error('Error retrieving user information', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $e->render($request);

        } catch (\Exception $e) {
            Log::error('Unexpected error retrieving user information', [
                'uid' => $request->input('uid'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => true,
                'message' => 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Stores a new email provider configuration.
     *
     * @param Request $request The incoming HTTP request, expecting configuration data.
     * @return JsonResponse A JSON response with the created configuration details.
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
                    'message' => 'Invalid input data',
                    'errors' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            $configData = $validator->validated();
            $oauthService = OAuthServiceFactory::create($configData['vec_provider_api']);
            $config = $oauthService->storeConfiguration($configData);

            return response()->json([
                'success' => true,
                'message' => 'Configuration stored successfully',
                'data' => [
                    'uid' => $config->uid,
                    'provider' => $config->vec_provider_api,
                    'vendor_id' => $config->vec_vendor_id,
                    'location_id' => $config->vec_location_id,
                    'user_email' => $config->vec_user_email,
                    'tenant_id' => $config->vec_tenant_id,
                    'created_at' => $config->TS_create->toISOString(),
                ]
            ], Response::HTTP_CREATED);

        } catch (OAuthException $e) {
            Log::error('Error storing configuration', [
                'provider' => $request->input('vec_provider_api'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $e->render($request);

        } catch (\Exception $e) {
            Log::error('Unexpected error storing configuration', [
                'provider' => $request->input('vec_provider_api'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => true,
                'message' => 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
