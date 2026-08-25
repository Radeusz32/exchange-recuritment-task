<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Wallet;
use App\Exception\InvalidAmountException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Repository\WalletRepositoryInterface;
use DateTimeImmutable;

readonly class DepositService
{
    public const float MAX_AMOUNT = 10000.0;

    public function __construct(
        private WalletRepositoryInterface $walletRepository,
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

        $wallet = $this->walletRepository->findById($walletId);
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
    }
}
