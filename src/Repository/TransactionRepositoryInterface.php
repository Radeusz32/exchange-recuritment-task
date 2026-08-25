<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Transaction;
use App\Enum\TransactionStatus;

interface TransactionRepositoryInterface
{
    public function findById(int $id): ?Transaction;

    /** @return Transaction[] */
    public function findByWalletId(int $walletId): array;

    /**
     * Transactions of every wallet the user owns, newest first, optionally narrowed
     * down to a single wallet.
     *
     * @return Transaction[]
     */
    public function findByUserId(int $userId, ?int $walletId = null, int $limit = 100): array;

    /** @return Transaction[] */
    public function findByStatus(TransactionStatus $status): array;

    public function save(Transaction $transaction): void;
}
