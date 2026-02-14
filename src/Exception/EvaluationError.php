<?php

namespace Nandan108\PropPath\Exception;

use Nandan108\PropPath\Contract\PropPathException;

final class EvaluationError extends \RuntimeException implements PropPathException
{
    /**
     * @param array<array-key, mixed> $messageParameters
     * @param array<array-key, mixed> $debug
     */
    public function __construct(
        string $message,
        public readonly EvaluationErrorCode $errorCode = EvaluationErrorCode::UNKNOWN,
        private readonly array $messageParameters = [],
        private readonly ?string $propertyPath = null,
        private readonly array $debug = [],
        int $httpCode = 422,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpCode, $previous);
    }

    public function getErrorCode(): EvaluationErrorCode
    {
        return $this->errorCode;
    }

    /** @return array<array-key, mixed> */
    public function getMessageParameters(): array
    {
        return $this->messageParameters;
    }

    public function getPropertyPath(): ?string
    {
        return $this->propertyPath;
    }

    /** @return array<array-key, mixed> */
    public function getDebugInfoMap(): array
    {
        return $this->debug;
    }
}
