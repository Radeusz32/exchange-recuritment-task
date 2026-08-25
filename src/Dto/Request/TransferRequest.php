<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Exception\InvalidRequestException;

final readonly class TransferRequest
{
    private function __construct(
        public int $fromWalletId,
        public int $toWalletId,
        public string $amount,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['fromWalletId', 'toWalletId', 'amount'] as $field) {
            if (!isset($data[$field])) {
                throw InvalidRequestException::missingField($field);
            }
        }

        return new self(
            fromWalletId: (int) $data['fromWalletId'],
            toWalletId: (int) $data['toWalletId'],
            amount: RequestAmount::parse($data['amount']),
        );
    }
}
