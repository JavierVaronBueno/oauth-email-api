<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Excepción base para errores de OAuth2.0
 *
 * Maneja errores relacionados con la autenticación OAuth2.0,
 * tokens de acceso, refresh tokens y procesos de autorización.
 */
class OAuthException extends Exception
{
    /**
     * Código de error específico del OAuth
     */
    protected ?string $oauthErrorCode;

    /**
     * Descripción detallada del error
     */
    protected ?string $oauthErrorDescription;

    /**
     * Datos adicionales del error
     */
    protected array $errorData;

    /**
     * Constructor de la excepción OAuth
     *
     * @param string $message Mensaje de error
     * @param int $code Código de error HTTP
     * @param string|null $oauthErrorCode Código específico del OAuth
     * @param string|null $oauthErrorDescription Descripción del error OAuth
     * @param Exception|null $previous Excepción previa
     * @param array $errorData Datos adicionales del error
     */
    public function __construct(
        string $message = 'Error de autenticación OAuth2.0',
        int $code = 401,
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
     * Obtiene el código de error OAuth específico
     */
    public function getOAuthErrorCode(): ?string
    {
        return $this->oauthErrorCode;
    }

    /**
     * Obtiene la descripción detallada del error OAuth
     */
    public function getOAuthErrorDescription(): ?string
    {
        return $this->oauthErrorDescription;
    }

    /**
     * Obtiene los datos adicionales del error
     */
    public function getErrorData(): array
    {
        return $this->errorData;
    }

    /**
     * Renderiza la excepción para respuesta HTTP
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
     * Crea una excepción para token expirado
     */
    public static function tokenExpired(string $provider = 'Unknown'): self
    {
        return new self(
            "Token de acceso expirado para {$provider}",
            401,
            'token_expired',
            'El token de acceso ha expirado y debe ser renovado'
        );
    }

    /**
     * Crea una excepción para token inválido
     */
    public static function invalidToken(string $provider = 'Unknown'): self
    {
        return new self(
            "Token de acceso inválido para {$provider}",
            401,
            'invalid_token',
            'El token de acceso proporcionado no es válido'
        );
    }

    /**
     * Crea una excepción para refresh token no disponible
     */
    public static function noRefreshToken(string $provider = 'Unknown'): self
    {
        return new self(
            "No hay refresh token disponible para {$provider}",
            401,
            'no_refresh_token',
            'No se puede renovar el token sin un refresh token válido'
        );
    }

    /**
     * Crea una excepción para código de autorización inválido
     */
    public static function invalidAuthorizationCode(): self
    {
        return new self(
            'Código de autorización inválido',
            400,
            'invalid_authorization_code',
            'El código de autorización recibido no es válido'
        );
    }

    /**
     * Crea una excepción para configuración inválida
     */
    public static function invalidConfiguration(string $details = ''): self
    {
        return new self(
            'Configuración OAuth2.0 inválida' . ($details ? ": {$details}" : ''),
            500,
            'invalid_configuration',
            'La configuración del cliente OAuth2.0 no es válida'
        );
    }
}
