<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Enum\TransactionStatus;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use DateTimeImmutable;

final readonly class TransactionProcessorService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
    ) {
    }

    public function complete(Transaction $transaction): void
    {
        if (!$this->isProcessable($transaction)) {
            return;
        }

        $fromWallet = $this->walletRepository->findById($transaction->getFromWalletId());
        $toWallet = $this->walletRepository->findById($transaction->getToWalletId());

        if (null === $fromWallet || null === $toWallet) {
            $this->reject($transaction);

            return;
        }

        // The wallets may have changed between the transfer request and its settlement:
        // the funds could have been spent by another pending transfer, or a wallet blocked.
        if ($fromWallet->isBlocked() || $toWallet->isBlocked()) {
            $this->reject($transaction);

            return;
        }

        if ((float) $transaction->getFromAmount() > $fromWallet->getBalance()) {
            $this->reject($transaction);

            return;
        }

        $fromWallet->setBalance($fromWallet->getBalance() - (float) $transaction->getFromAmount());
        $fromWallet->setLastActivityAt(new DateTimeImmutable());

        $toWallet->setBalance($toWallet->getBalance() + (float) $transaction->getToAmount());
        $toWallet->setLastActivityAt(new DateTimeImmutable());

        $this->walletRepository->save($fromWallet);
        $this->walletRepository->save($toWallet);

        $transaction->setStatus(TransactionStatus::COMPLETED);

        if ($transaction->requiresAntiFraudCheck()) {
            $transaction->setAntiFraudCheckedAt(new DateTimeImmutable());
        }

        $this->transactionRepository->save($transaction);
    }

    public function reject(Transaction $transaction): void
    {
        if (!$this->isProcessable($transaction)) {
            return;
        }

        $transaction->setStatus(TransactionStatus::REJECTED);

        if ($transaction->requiresAntiFraudCheck()) {
            $transaction->setAntiFraudCheckedAt(new DateTimeImmutable());
        }

        // No wallet is touched: a rejected transfer never moved any funds in the first place.
        $this->transactionRepository->save($transaction);
    }

    /**
     * Only transactions awaiting processing may be settled - this guards against
     * processing the same transaction twice, which would move the funds twice.
     */
    private function isProcessable(Transaction $transaction): bool
    {
        return in_array(
            $transaction->getStatus(),
            [TransactionStatus::PENDING, TransactionStatus::FRAUD_REVIEW],
            true,
        );
    }
}
