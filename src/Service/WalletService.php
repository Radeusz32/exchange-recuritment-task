<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\WalletAlreadyExistsException;
use App\Exception\WalletHasPendingTransactionsException;
use App\Exception\WalletNotEmptyException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;

readonly class WalletService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
    ) {
    }

    public function createWallet(int $userId, Currency $currency): Wallet
    {
        $existing = $this->walletRepository->findByUserIdAndCurrency($userId, $currency);

        if (null !== $existing && !$existing->isDeleted()) {
            throw new WalletAlreadyExistsException($userId, $currency);
        }

        // A user may only hold one wallet per currency, so a wallet deleted earlier
        // is restored instead of inserting a second row for the same currency.
        if (null !== $existing) {
            $existing->restore();
            $this->walletRepository->save($existing);

            return $existing;
        }

        $wallet = Wallet::create($userId, $currency);
        $this->walletRepository->save($wallet);

        return $wallet;
    }

    public function deleteWallet(int $userId, int $walletId): void
    {
        $wallet = $this->walletRepository->findById($walletId);

        if (null === $wallet || $wallet->getUserId() !== $userId) {
            throw new WalletNotFoundException($walletId);
        }

        // Rounded to the scale the balance is stored with, so leftovers far below
        // a minor unit do not keep a wallet alive forever.
        if (0.0 !== round($wallet->getBalance(), 4)) {
            throw new WalletNotEmptyException($walletId);
        }

        if ($this->hasTransactionsAwaitingProcessing($walletId)) {
            throw new WalletHasPendingTransactionsException($walletId);
        }

        $wallet->delete();
        $this->walletRepository->save($wallet);
    }

    private function hasTransactionsAwaitingProcessing(int $walletId): bool
    {
        foreach ($this->transactionRepository->findByWalletId($walletId) as $transaction) {
            if (in_array(
                $transaction->getStatus(),
                [TransactionStatus::PENDING, TransactionStatus::FRAUD_REVIEW],
                true,
            )) {
                return true;
            }
        }

        return false;
    }
}
