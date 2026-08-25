<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class InvalidAmountException extends RuntimeException
{
    public static function notPositive(): self
    {
        return new self('Amount must be a positive number.');
    }

    public static function exceedsMaximum(float $maxAmount): self
    {
        return new self(sprintf('Amount cannot exceed %s.', $maxAmount));
    }
}
