<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Exception\InvalidRequestException;

final readonly class DepositRequest
{
    private function __construct(public string $amount)
    {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (!isset($data['amount'])) {
            throw InvalidRequestException::missingField('amount');
        }

        return new self(RequestAmount::parse($data['amount']));
    }
}
