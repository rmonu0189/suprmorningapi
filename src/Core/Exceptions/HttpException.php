<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;

class HttpException extends RuntimeException
{
    private int $statusCode;
    private array $context;

    public function __construct(string $message, int $statusCode, array $context = [])
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->context = $context;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function context(): array
    {
        return $this->context;
    }
}
