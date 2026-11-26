<?php

declare(strict_types=1);

namespace Daraja\Exception;

/**
 * Daraja answered with an error. The Daraja error code and message are preserved
 * exactly as received so you can log and branch on them.
 */
class ApiException extends DarajaException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly string $errorCode = '',
        public readonly string $errorMessage = '',
        public readonly string $requestId = '',
        public readonly string $rawBody = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    /**
     * Build from an HTTP error response. Daraja error bodies look like
     * {"requestId": "...", "errorCode": "400.002.02", "errorMessage": "Bad Request - ..."}.
     */
    public static function fromResponse(int $status, string $body): self
    {
        $code = '';
        $message = '';
        $requestId = '';
        $decoded = json_decode($body, true);
        if (\is_array($decoded)) {
            $code = self::scalar($decoded['errorCode'] ?? '');
            $message = self::scalar($decoded['errorMessage'] ?? '');
            $requestId = self::scalar($decoded['requestId'] ?? '');
        }

        $summary = $message !== '' ? $message : 'Daraja returned HTTP ' . $status;
        if ($code !== '') {
            $summary = \sprintf('[%s] %s', $code, $summary);
        }

        return new self($summary, $status, $code, $message, $requestId, mb_substr($body, 0, 2000));
    }

    /** Build from a 2xx response whose ResponseCode says the request was not accepted. */
    public static function fromResponseCode(string $code, string $description, int $status = 200): self
    {
        return new self(
            \sprintf('[%s] %s', $code, $description !== '' ? $description : 'Request not accepted'),
            $status,
            $code,
            $description,
        );
    }

    /** True for 5xx answers and 429; resubmitting a payment request blindly is still your call. */
    public function isServerSide(): bool
    {
        return $this->httpStatus >= 500 || $this->httpStatus === 429;
    }

    private static function scalar(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
