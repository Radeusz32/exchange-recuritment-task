<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Exception\InvalidRequestException;

/**
 * Amounts travel as strings so no precision is lost on the way in, but they still have
 * to look like a positive number before any service is handed them.
 */
final readonly class RequestAmount
{
    public static function parse(mixed $amount): string
    {
        if (!is_string($amount) && !is_int($amount) && !is_float($amount)) {
            throw InvalidRequestException::notAPositiveNumber();
        }

        if (!is_numeric($amount) || (float) $amount <= 0) {
            throw InvalidRequestException::notAPositiveNumber();
        }

        return (string) $amount;
    }
}
