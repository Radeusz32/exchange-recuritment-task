<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class WalletHasPendingTransactionsException extends RuntimeException
{
    public function __construct(int $walletId)
    {
        parent::__construct(
            sprintf('Wallet %d has transactions awaiting processing and cannot be deleted.', $walletId),
        );
    }
}
