<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Enum\Currency;
use App\Exception\InsufficientFundsException;
use App\Exception\InvalidAmountException;
use App\Exception\SameWalletTransferException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;

readonly class TransferService
{
    /** Transfers worth more than this amount in EUR must be approved manually. */
    public const float ANTI_FRAUD_THRESHOLD_EUR = 15_000.0;

    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private ExchangeRateService $exchangeRateService,
        private SpreadService $spreadService,
    ) {
    }

    public function transfer(
        int $userId,
        int $fromWalletId,
        int $toWalletId,
        string $fromAmount,
    ): Transaction {
        if ((float) $fromAmount <= 0) {
            throw InvalidAmountException::notPositive();
        }

        if ($fromWalletId === $toWalletId) {
            throw new SameWalletTransferException($fromWalletId);
        }

        $fromWallet = $this->walletRepository->findById($fromWalletId);
        if (null === $fromWallet || $fromWallet->getUserId() !== $userId) {
            throw new WalletNotFoundException($fromWalletId);
        }

        $toWallet = $this->walletRepository->findById($toWalletId);
        if (null === $toWallet || $toWallet->getUserId() !== $userId) {
            throw new WalletNotFoundException($toWalletId);
        }

        if ($fromWallet->isBlocked()) {
            throw new WalletBlockedException($fromWalletId);
        }

        if ($toWallet->isBlocked()) {
            throw new WalletBlockedException($toWalletId);
        }

        if ((float) $fromAmount > $fromWallet->getBalance()) {
            throw new InsufficientFundsException($fromWalletId);
        }

        $fromCurrency = $fromWallet->getCurrency();
        $toCurrency = $toWallet->getCurrency();

        $exchangeRate = $this->exchangeRateService->getExchangeRateBetween($fromCurrency, $toCurrency);
        $rawToAmount = (float) $fromAmount * $exchangeRate;
        $spread = $this->spreadService->calculateSpread($rawToAmount, $fromCurrency, $toCurrency);
        $toAmount = $rawToAmount - (float) $spread;

        $toAmountFormatted = number_format($toAmount, 4, '.', '');

        // Funds are not moved here on purpose — a transfer only records the intent.
        // Balances change once the transaction is completed by TransactionProcessorService,
        // which for large amounts happens only after the anti-fraud approval.
        $transaction = Transaction::create(
            fromWalletId: $fromWalletId,
            toWalletId: $toWalletId,
            fromAmount: $fromAmount,
            toAmount: $toAmountFormatted,
            fromCurrency: $fromCurrency,
            toCurrency: $toCurrency,
            spread: $spread,
            exchangeRate: number_format($exchangeRate, 6, '.', ''),
            requiresAntiFraudCheck: $this->requiresAntiFraudCheck((float) $fromAmount, $fromCurrency),
        );

        $this->transactionRepository->save($transaction);

        return $transaction;
    }

    /**
     * The threshold is defined in euro, so the transferred amount has to be converted
     * to EUR first — comparing an amount denominated in JPY or HUF against it would
     * flag ordinary transfers, while GBP or CHF ones would slip through.
     */
    private function requiresAntiFraudCheck(float $amount, Currency $currency): bool
    {
        $amountInEur = $amount * $this->exchangeRateService->getExchangeRateBetween($currency, Currency::EUR);

        return $amountInEur > self::ANTI_FRAUD_THRESHOLD_EUR;
    }
}
