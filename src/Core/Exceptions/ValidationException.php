<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class ValidationException extends HttpException
{
    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message, 422, ['errors' => $errors]);
    }
}
