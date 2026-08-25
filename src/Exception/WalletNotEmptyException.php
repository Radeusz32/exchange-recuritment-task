<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class WalletNotEmptyException extends RuntimeException
{
    public function __construct(int $walletId)
    {
        parent::__construct(
            sprintf('Wallet %d still holds funds and cannot be deleted.', $walletId),
        );
    }
}
