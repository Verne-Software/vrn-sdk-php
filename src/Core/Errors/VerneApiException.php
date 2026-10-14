<?php

declare(strict_types=1);

namespace Vernesoft\Core\Errors;

/**
 * Thrown when the Verne API answers with an error status.
 *
 * Branch on the HTTP status (`getCode()`) and the machine-readable code
 * (`getErrorCode()`). Codes are an open set: compare against the ones you handle and
 * treat the rest generically.
 */
class VerneApiException extends VerneException
{
    /**
     * The members of the `error` object other than `code`, `message` and `request_id` —
     * for example `plan`, `limit` and `current` on a `402` limit refusal, `kind` on a
     * Clockwork one, `required_aal` and `current_aal` on `403 mfa_required`. Empty when
     * there are none.
     *
     * @var array<string, mixed>
     */
    public readonly array $details;

    /**
     * Seconds from the `Retry-After` header, when the response had one. On a `429` it is
     * how long to wait; for `RELAY_MESSAGE_LIMIT_REACHED` it runs to the 1st of next month.
     */
    public readonly ?int $retryAfter;

    /** The parsed response body (or the raw text when it was not JSON), for diagnosis. */
    public readonly mixed $body;

    private readonly string $errorCode;

    private readonly ?string $requestId;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $errorCode,
        string $message,
        int $httpStatus,
        ?string $requestId = null,
        array $details = [],
        ?int $retryAfter = null,
        mixed $body = null,
    ) {
        parent::__construct($message, $httpStatus);
        $this->errorCode = $errorCode;
        $this->requestId = $requestId;
        $this->details = $details;
        $this->retryAfter = $retryAfter;
        $this->body = $body;
    }

    /**
     * Builds the exception from a response body of any shape. Never throws.
     *
     * Every API route answers with the envelope `{"error": {"code", "message",
     * "request_id", …}}`. Other shapes are still read, as a fallback that costs nothing:
     * the string form `{"error": "<sentence>"}` Clockwork used before October 2026, an
     * identity-service body with a numeric `code` (still what Kratos' own `/auth/*`
     * self-service routes answer), a proxy answering HTML. Anything unrecognised degrades
     * to code `unknown` and message `HTTP <status>`.
     */
    public static function fromResponse(int $status, mixed $body, ?int $retryAfter = null): self
    {
        $envelope = is_array($body) && isset($body['error']) && is_array($body['error']) && ! array_is_list($body['error'])
            ? $body['error']
            : null;

        $details = [];
        if ($envelope !== null) {
            foreach ($envelope as $key => $value) {
                if (! in_array($key, ['code', 'message', 'request_id'], true)) {
                    $details[(string) $key] = $value;
                }
            }
        }

        $code = $envelope !== null && isset($envelope['code']) && is_string($envelope['code']) && $envelope['code'] !== ''
            ? $envelope['code']
            : 'unknown';

        $requestId = $envelope !== null && isset($envelope['request_id']) && is_string($envelope['request_id'])
            ? $envelope['request_id']
            : null;

        return new self(
            errorCode: $code,
            message: self::extractMessage($body, $status),
            httpStatus: $status,
            requestId: $requestId,
            details: $details,
            retryAfter: $retryAfter,
            body: $body,
        );
    }

    /**
     * Machine-readable error code, e.g. `invalid_payload`, `forbidden`, `conflict`,
     * `mfa_required`, `ACTIVE_USER_LIMIT_REACHED`. `unknown` when the response carried no
     * string code (a Clockwork engine error, a pass-through body, a body that is not JSON).
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** The HTTP status code (the same as `getCode()`). */
    public function getStatus(): int
    {
        return $this->getCode();
    }

    /**
     * Request id to quote to support. `null` when the response did not carry one.
     */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    private static function extractMessage(mixed $body, int $status): string
    {
        if (is_array($body)) {
            $error = $body['error'] ?? null;

            if (is_array($error) && isset($error['message']) && is_string($error['message']) && $error['message'] !== '') {
                return $error['message'];
            }

            if (is_string($error) && trim($error) !== '') {
                return $error;
            }

            $detail = $body['detail'] ?? null;
            if (is_string($detail) && $detail !== '') {
                return $detail;
            }

            if (is_array($detail)) {
                $messages = [];
                foreach ($detail as $item) {
                    if (is_array($item) && isset($item['msg']) && is_string($item['msg'])) {
                        $messages[] = $item['msg'];
                    }
                }
                if ($messages !== []) {
                    return implode('; ', $messages);
                }
            }

            if (isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
                return $body['message'];
            }
        }

        return 'HTTP '.$status;
    }
}
