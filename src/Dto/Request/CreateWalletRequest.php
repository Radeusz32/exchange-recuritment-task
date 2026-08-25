<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Enum\Currency;
use App\Exception\InvalidRequestException;
use ValueError;

final readonly class CreateWalletRequest
{
    private function __construct(public Currency $currency)
    {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (!isset($data['currency']) || !is_string($data['currency'])) {
            throw InvalidRequestException::missingField('currency');
        }

        try {
            return new self(Currency::from($data['currency']));
        } catch (ValueError) {
            throw InvalidRequestException::invalidCurrency();
        }
    }
}
