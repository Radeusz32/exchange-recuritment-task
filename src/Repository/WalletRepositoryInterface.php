<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Wallet;
use App\Enum\Currency;

interface WalletRepositoryInterface
{
    public function findById(int $id): ?Wallet;

    /** Reads the wallet and locks its row for the rest of the current database transaction. */
    public function findByIdForUpdate(int $id): ?Wallet;

    /** @return Wallet[] */
    public function findByUserId(int $userId): array;

    public function findByUserIdAndCurrency(int $userId, Currency $currency): ?Wallet;

    public function save(Wallet $wallet): void;
}
