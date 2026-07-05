<?php

namespace App\Services\Providers;

use RuntimeException;

class ProviderException extends RuntimeException
{
    public function __construct(string $message, protected ?int $statusCode = null)
    {
        parent::__construct($message);
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }
}
