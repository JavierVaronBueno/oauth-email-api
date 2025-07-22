<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Exception for email sending errors.
 *
 * Handles errors related to sending emails
 * through Microsoft Graph and Google APIs.
 */
class EmailException extends Exception
{
    /**
     * Type of email error.
     *
     * @var string
     */
    protected string $emailErrorType;

    /**
     * Data of the email that caused the error.
     *
     * @var array
     */
    protected array $emailData;

    /**
     * Email exception constructor.
     *
     * @param string $message The error message.
     * @param int $code The HTTP status code.
     * @param string $emailErrorType The type of email error.
     * @param array $emailData The data of the email.
     * @param Exception|null $previous The previous exception, if any.
     */
    public function __construct(
        string $message = 'Error sending email',
        int $code = Response::HTTP_INTERNAL_SERVER_ERROR,
        string $emailErrorType = 'send_error',
        array $emailData = [],
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);

        $this->emailErrorType = $emailErrorType;
        $this->emailData = $emailData;
    }

    /**
     * Get the type of email error.
     *
     * @return string
     */
    public function getEmailErrorType(): string
    {
        return $this->emailErrorType;
    }

    /**
     * Get the email data.
     *
     * @return array
     */
    public function getEmailData(): array
    {
        return $this->emailData;
    }

    /**
     * Render the exception as an HTTP response.
     *
     * @param Request $request The current HTTP request.
     * @return JsonResponse
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

        // Do not include sensitive email data in the response
        $safeEmailData = array_intersect_key($this->emailData, [
            'to' => true,
            'subject' => true,
            'cc' => true,
            'bcc' => true,
        ]);

        if (!empty($safeEmailData)) {
            $errorResponse['email_data'] = $safeEmailData;
        }

        // Log the error with detailed information
        Log::error('Email Error: ' . $this->getMessage(), [
            'email_error_type' => $this->emailErrorType,
            'email_data' => $this->emailData,
            'trace' => $this->getTraceAsString(),
        ]);

        return response()->json($errorResponse, $this->getCode());
    }

    /**
     * Create an exception for an invalid recipient.
     *
     * @param string $email The invalid email address.
     * @param string $provider The email service provider.
     * @return static
     */
    public static function invalidRecipient(string $email, string $provider = 'Unknown'): self
    {
        return new self(
            "Invalid email recipient: {$email}",
            Response::HTTP_BAD_REQUEST,
            'invalid_recipient',
            ['to' => $email, 'provider' => $provider]
        );
    }

    /**
     * Create an exception for an empty subject.
     *
     * @param string $provider The email service provider.
     * @return static
     */
    public static function emptySubject(string $provider = 'Unknown'): self
    {
        return new self(
            'Email subject cannot be empty',
            Response::HTTP_BAD_REQUEST,
            'empty_subject',
            ['provider' => $provider]
        );
    }

    /**
     * Create an exception for empty content.
     *
     * @param string $provider The email service provider.
     * @return static
     */
    public static function emptyContent(string $provider = 'Unknown'): self
    {
        return new self(
            'Email content cannot be empty',
            Response::HTTP_BAD_REQUEST,
            'empty_content',
            ['provider' => $provider]
        );
    }

    /**
     * Create an exception for exceeding the size limit.
     *
     * @param int $size The current email size in bytes.
     * @param int $maxSize The maximum allowed email size in bytes.
     * @param string $provider The email service provider.
     * @return static
     */
    public static function sizeLimitExceeded(int $size, int $maxSize, string $provider = 'Unknown'): self
    {
        return new self(
            "Email size exceeded: {$size} bytes. Maximum allowed: {$maxSize} bytes",
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            'size_limit_exceeded',
            ['size' => $size, 'max_size' => $maxSize, 'provider' => $provider]
        );
    }

    /**
     * Create an exception for exceeding the sending limit.
     *
     * @param string $provider The email service provider.
     * @return static
     */
    public static function sendLimitExceeded(string $provider = 'Unknown'): self
    {
        return new self(
            'Email sending limit exceeded',
            Response::HTTP_TOO_MANY_REQUESTS,
            'send_limit_exceeded',
            ['provider' => $provider]
        );
    }

    /**
     * Create an exception for an invalid attachment.
     *
     * @param string $filename The name of the invalid file.
     * @param string $reason The reason for the invalid attachment.
     * @return static
     */
    public static function invalidAttachment(string $filename, string $reason = ''): self
    {
        return new self(
            "Invalid attachment: {$filename}" . ($reason ? " - {$reason}" : ''),
            Response::HTTP_BAD_REQUEST,
            'invalid_attachment',
            ['filename' => $filename, 'reason' => $reason]
        );
    }

    /**
     * Create an exception for an invalid email format.
     *
     * @param string $field The field with the invalid email format (e.g., 'to', 'cc').
     * @param string $value The value that caused the invalid format.
     * @return static
     */
    public static function invalidEmailFormat(string $field, string $value): self
    {
        return new self(
            "Invalid email format in {$field}: {$value}",
            Response::HTTP_BAD_REQUEST,
            'invalid_email_format',
            ['field' => $field, 'value' => $value]
        );
    }

    /**
     * Create an exception for an unavailable provider.
     *
     * @param string $provider The unavailable email service provider.
     * @return static
     */
    public static function providerUnavailable(string $provider): self
    {
        return new self(
            "Email service provider unavailable: {$provider}",
            Response::HTTP_SERVICE_UNAVAILABLE,
            'provider_unavailable',
            ['provider' => $provider]
        );
    }

    /**
     * Create an exception for exceeded quota.
     *
     * @param string $provider The email service provider.
     * @param int $currentUsage The current usage.
     * @param int $limit The quota limit.
     * @return static
     */
    public static function quotaExceeded(string $provider, int $currentUsage, int $limit): self
    {
        return new self(
            "Email quota exceeded for {$provider}: {$currentUsage}/{$limit}",
            Response::HTTP_FORBIDDEN,
            'quota_exceeded',
            ['provider' => $provider, 'current_usage' => $currentUsage, 'limit' => $limit]
        );
    }

    /**
     * Create an exception for invalid email configuration.
     *
     * @param string $provider The email service provider.
     * @param string $details Additional details about the invalid configuration.
     * @return static
     */
    public static function invalidConfiguration(string $provider, string $details = ''): self
    {
        return new self(
            "Invalid email configuration for {$provider}" . ($details ? ": {$details}" : ''),
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'invalid_configuration',
            ['provider' => $provider, 'details' => $details]
        );
    }

    /**
     * Create an exception for a send timeout.
     *
     * @param string $provider The email service provider.
     * @param int $timeout The timeout duration in seconds.
     * @return static
     */
    public static function sendTimeout(string $provider, int $timeout): self
    {
        return new self(
            "Email send timeout with {$provider}: {$timeout} seconds",
            Response::HTTP_REQUEST_TIMEOUT,
            'send_timeout',
            ['provider' => $provider, 'timeout' => $timeout]
        );
    }

    /**
     * Create an exception for a network error.
     *
     * @param string $provider The email service provider.
     * @param string $details Additional details about the network error.
     * @return static
     */
    public static function networkError(string $provider, string $details = ''): self
    {
        return new self(
            "Network error with {$provider}" . ($details ? ": {$details}" : ''),
            Response::HTTP_BAD_GATEWAY,
            'network_error',
            ['provider' => $provider, 'details' => $details]
        );
    }
}
