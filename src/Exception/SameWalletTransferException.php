<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class SameWalletTransferException extends RuntimeException
{
    public function __construct(int $walletId)
    {
        parent::__construct(sprintf('Cannot transfer funds from wallet %d to itself.', $walletId));
    }
}
