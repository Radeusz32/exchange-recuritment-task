<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class InvalidRequestException extends RuntimeException
{
    public static function missingField(string $field): self
    {
        return new self(sprintf('Missing required field: %s.', $field));
    }

    public static function notAPositiveNumber(): self
    {
        return new self('Amount must be a positive number.');
    }

    public static function invalidCurrency(): self
    {
        return new self('Invalid currency.');
    }

    public static function malformedBody(): self
    {
        return new self('Invalid JSON body.');
    }
}
