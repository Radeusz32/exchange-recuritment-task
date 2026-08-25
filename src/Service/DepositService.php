<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Wallet;
use App\Exception\InvalidAmountException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Persistence\AtomicOperationRunnerInterface;
use App\Repository\WalletRepositoryInterface;
use DateTimeImmutable;

readonly class DepositService
{
    public const float MAX_AMOUNT = 10000.0;

    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private AtomicOperationRunnerInterface $atomicOperationRunner,
    ) {
    }

    public function deposit(int $userId, int $walletId, string $amount): Wallet
    {
        // Enforced here rather than only in the controller, so the limit holds
        // for every caller of the service.
        if ((float) $amount <= 0) {
            throw InvalidAmountException::notPositive();
        }

        if ((float) $amount > self::MAX_AMOUNT) {
            throw InvalidAmountException::exceedsMaximum(self::MAX_AMOUNT);
        }

        // Locking the wallet inside a transaction keeps two deposits landing at the same
        // time from reading the same balance and one of them overwriting the other.
        return $this->atomicOperationRunner->run(function () use ($userId, $walletId, $amount): Wallet {
            $wallet = $this->walletRepository->findByIdForUpdate($walletId);
            if (null === $wallet || $wallet->getUserId() !== $userId) {
                throw new WalletNotFoundException($walletId);
            }

            if ($wallet->isBlocked()) {
                throw new WalletBlockedException($walletId);
            }

            $wallet->setBalance($wallet->getBalance() + (float) $amount);
            $wallet->setLastActivityAt(new DateTimeImmutable());
            $this->walletRepository->save($wallet);

            return $wallet;
        });
    }
}
