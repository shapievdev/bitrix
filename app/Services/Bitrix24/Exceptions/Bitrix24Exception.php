<?php

namespace App\Services\Bitrix24\Exceptions;

use RuntimeException;

class Bitrix24Exception extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?string $method = null,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(string $method, array $payload): self
    {
        $code = $payload['error'] ?? 'unknown_error';
        $description = $payload['error_description'] ?? 'Битрикс24 вернул ошибку без описания';

        return new self(
            "REST {$method} → {$code}: {$description}",
            errorCode: $code,
            method: $method,
            context: $payload,
        );
    }
}
