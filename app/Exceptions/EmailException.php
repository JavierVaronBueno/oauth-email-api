<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Excepción para errores de envío de correo electrónico
 *
 * Maneja errores relacionados con el envío de correos electrónicos
 * a través de las APIs de Microsoft Graph y Google.
 */
class EmailException extends Exception
{
    /**
     * Tipo de error de email
     */
    protected string $emailErrorType;

    /**
     * Datos del email que causó el error
     */
    protected array $emailData;

    /**
     * Constructor de la excepción de email
     *
     * @param string $message Mensaje de error
     * @param int $code Código de error HTTP
     * @param string $emailErrorType Tipo de error de email
     * @param array $emailData Datos del email
     * @param Exception|null $previous Excepción previa
     */
    public function __construct(
        string $message = 'Error al enviar correo electrónico',
        int $code = 500,
        string $emailErrorType = 'send_error',
        array $emailData = [],
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);

        $this->emailErrorType = $emailErrorType;
        $this->emailData = $emailData;
    }

    /**
     * Obtiene el tipo de error de email
     */
    public function getEmailErrorType(): string
    {
        return $this->emailErrorType;
    }

    /**
     * Obtiene los datos del email
     */
    public function getEmailData(): array
    {
        return $this->emailData;
    }

    /**
     * Renderiza la excepción para respuesta HTTP
     */
    public function render(Request $request): JsonResponse
    {
        $errorResponse = [
            'error' => true,
            'message' => $this->getMessage(),
            'error_type' => 'email_error',
            'email_error_type' => $this->emailErrorType,
            'http_code' => $this->getCode(),
        ];

        // No incluir datos sensibles del email en la respuesta
        $safeEmailData = array_intersect_key($this->emailData, [
            'to' => true,
            'subject' => true,
            'cc' => true,
            'bcc' => true,
        ]);

        if (!empty($safeEmailData)) {
            $errorResponse['email_data'] = $safeEmailData;
        }

        // Log del error para debugging
        Log::error('Email Error: ' . $this->getMessage(), [
            'email_error_type' => $this->emailErrorType,
            'email_data' => $this->emailData,
            'trace' => $this->getTraceAsString(),
        ]);

        return response()->json($errorResponse, $this->getCode());
    }

    /**
     * Crea una excepción para destinatario inválido
     */
    public static function invalidRecipient(string $email, string $provider = 'Unknown'): self
    {
        return new self(
            "Destinatario de correo inválido: {$email}",
            400,
            'invalid_recipient',
            ['to' => $email, 'provider' => $provider]
        );
    }

    /**
     * Crea una excepción para asunto vacío
     */
    public static function emptySubject(string $provider = 'Unknown'): self
    {
        return new self(
            'El asunto del correo no puede estar vacío',
            400,
            'empty_subject',
            ['provider' => $provider]
        );
    }

    /**
     * Crea una excepción para contenido vacío
     */
    public static function emptyContent(string $provider = 'Unknown'): self
    {
        return new self(
            'El contenido del correo no puede estar vacío',
            400,
            'empty_content',
            ['provider' => $provider]
        );
    }

    /**
     * Crea una excepción para límite de tamaño excedido
     */
    public static function sizeLimitExceeded(int $size, int $maxSize, string $provider = 'Unknown'): self
    {
        return new self(
            "Tamaño del correo excedido: {$size} bytes. Máximo permitido: {$maxSize} bytes",
            413,
            'size_limit_exceeded',
            ['size' => $size, 'max_size' => $maxSize, 'provider' => $provider]
        );
    }

    /**
     * Crea una excepción para límite de envío excedido
     */
    public static function sendLimitExceeded(string $provider = 'Unknown'): self
    {
        return new self(
            'Límite de envío de correos excedido',
            429,
            'send_limit_exceeded',
            ['provider' => $provider]
        );
    }

    /**
     * Crea una excepción para archivo adjunto inválido
     */
    public static function invalidAttachment(string $filename, string $reason = ''): self
    {
        return new self(
            "Archivo adjunto inválido: {$filename}" . ($reason ? " - {$reason}" : ''),
            400,
            'invalid_attachment',
            ['filename' => $filename, 'reason' => $reason]
        );
    }

    /**
     * Crea una excepción para formato de email inválido
     */
    public static function invalidEmailFormat(string $field, string $value): self
    {
        return new self(
            "Formato de email inválido en {$field}: {$value}",
            400,
            'invalid_email_format',
            ['field' => $field, 'value' => $value]
        );
    }

    /**
     * Crea una excepción para proveedor no disponible
     */
    public static function providerUnavailable(string $provider): self
    {
        return new self(
            "Proveedor de correo no disponible: {$provider}",
            503,
            'provider_unavailable',
            ['provider' => $provider]
        );
    }

    /**
     * Crea una excepción para quota excedida
     */
    public static function quotaExceeded(string $provider, int $currentUsage, int $limit): self
    {
        return new self(
            "Quota de correo excedida en {$provider}: {$currentUsage}/{$limit}",
            429,
            'quota_exceeded',
            ['provider' => $provider, 'current_usage' => $currentUsage, 'limit' => $limit]
        );
    }

    /**
     * Crea una excepción para configuración de correo inválida
     */
    public static function invalidConfiguration(string $provider, string $details = ''): self
    {
        return new self(
            "Configuración de correo inválida para {$provider}" . ($details ? ": {$details}" : ''),
            500,
            'invalid_configuration',
            ['provider' => $provider, 'details' => $details]
        );
    }

    /**
     * Crea una excepción para timeout de envío
     */
    public static function sendTimeout(string $provider, int $timeout): self
    {
        return new self(
            "Timeout al enviar correo con {$provider}: {$timeout} segundos",
            408,
            'send_timeout',
            ['provider' => $provider, 'timeout' => $timeout]
        );
    }

    /**
     * Crea una excepción para error de red
     */
    public static function networkError(string $provider, string $details = ''): self
    {
        return new self(
            "Error de red con {$provider}" . ($details ? ": {$details}" : ''),
            500,
            'network_error',
            ['provider' => $provider, 'details' => $details]
        );
    }
}
