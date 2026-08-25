<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\TransactionStatus;
use App\Persistence\AtomicOperationRunnerInterface;
use App\Repository\CompanyWalletRepositoryInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use DateTimeImmutable;

final readonly class TransactionProcessorService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private CompanyWalletRepositoryInterface $companyWalletRepository,
        private AtomicOperationRunnerInterface $atomicOperationRunner,
    ) {
    }

    public function complete(Transaction $transaction): void
    {
        if (!$this->isProcessable($transaction)) {
            return;
        }

        // Settling a transaction means four writes: debit, credit, the company's spread
        // and the new status. A failure in between would leave money taken from one
        // wallet and never handed to the other, so they all go in or none of them does.
        $this->atomicOperationRunner->run(function () use ($transaction): void {
            $this->settle($transaction);
        });
    }

    private function settle(Transaction $transaction): void
    {
        $wallets = $this->lockWallets($transaction->getFromWalletId(), $transaction->getToWalletId());

        $fromWallet = $wallets[$transaction->getFromWalletId()];
        $toWallet = $wallets[$transaction->getToWalletId()];

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

        // The spread was deducted from what the client receives, so this is what the
        // company earns on the exchange — held in the currency the client was paid in.
        $this->companyWalletRepository->addToBalance($transaction->getToCurrency(), $transaction->getSpread());

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
     * Locks both wallets before their balances are read, so a concurrent settlement or
     * deposit cannot slip in between the read and the write and have its change lost.
     *
     * The wallets are locked in a fixed order (by id), otherwise two opposite transfers
     * running at the same time would each hold what the other one waits for.
     *
     * @return array<int, ?Wallet>
     */
    private function lockWallets(int $fromWalletId, int $toWalletId): array
    {
        $walletIds = [$fromWalletId, $toWalletId];
        sort($walletIds);

        $wallets = [];

        foreach ($walletIds as $walletId) {
            $wallets[$walletId] = $this->walletRepository->findByIdForUpdate($walletId);
        }

        return $wallets;
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
