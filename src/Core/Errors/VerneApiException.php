<?php

declare(strict_types=1);

namespace Vernesoft\Core\Errors;

class VerneApiException extends VerneException
{
    public function __construct(
        private string $errorCode,
        string $message,
        int $httpStatus,
        private string $requestId,
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }
}
