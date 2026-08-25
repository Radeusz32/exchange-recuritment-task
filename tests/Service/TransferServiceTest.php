<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\InsufficientFundsException;
use App\Exception\SameWalletTransferException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\ExchangeRateService;
use App\Service\SpreadService;
use App\Service\TransferService;
use Generator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TransferServiceTest extends TestCase
{
    private const float ANTI_FRAUD_THRESHOLD_EUR = 15000.0;

    private WalletRepositoryInterface&MockObject $walletRepository;
    private TransactionRepositoryInterface&MockObject $transactionRepository;
    private ExchangeRateService&MockObject $exchangeRateService;
    private SpreadService&MockObject $spreadService;
    private TransferService $transferService;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->exchangeRateService = $this->createMock(ExchangeRateService::class);
        $this->spreadService = $this->createMock(SpreadService::class);

        $this->transferService = new TransferService(
            $this->walletRepository,
            $this->transactionRepository,
            $this->exchangeRateService,
            $this->spreadService,
            self::ANTI_FRAUD_THRESHOLD_EUR,
        );
    }

    public function testTransferCreatesPendingTransactionWithoutMovingFunds(): void
    {
        $userId = 1;

        $fromWallet = Wallet::create($userId, Currency::PLN);
        $fromWallet->setBalance(5000.0);

        $toWallet = Wallet::create($userId, Currency::EUR);
        $toWallet->setBalance(100.0);

        $this->walletRepository
            ->expects(self::exactly(2))
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->exchangeRateService
            ->method('getExchangeRateBetween')
            ->willReturnMap([
                [Currency::PLN, Currency::EUR, 0.25],
            ]);

        $this->spreadService
            ->expects(self::once())
            ->method('calculateSpread')
            ->with(250.0, Currency::PLN, Currency::EUR)
            ->willReturn('1.0000');

        // Funds must not move until the transaction is processed.
        $this->walletRepository
            ->expects(self::never())
            ->method('save');

        $this->transactionRepository
            ->expects(self::once())
            ->method('save')
            ->with($this->isInstanceOf(Transaction::class));

        $transaction = $this->transferService->transfer($userId, 1, 2, '1000.00');

        self::assertSame(5000.0, $fromWallet->getBalance());
        self::assertSame(100.0, $toWallet->getBalance());
        self::assertSame(TransactionStatus::PENDING, $transaction->getStatus());
        self::assertFalse($transaction->requiresAntiFraudCheck());
        self::assertSame('1000.00', $transaction->getFromAmount());
        self::assertSame('249.0000', $transaction->getToAmount());
        self::assertSame('0.250000', $transaction->getExchangeRate());
        self::assertSame('1.0000', $transaction->getSpread());
        self::assertSame(Currency::PLN, $transaction->getFromCurrency());
        self::assertSame(Currency::EUR, $transaction->getToCurrency());
    }

    /**
     * The threshold is 15 000 EUR of the transferred amount, regardless of the currencies
     * involved. The target amount alone tells nothing: 100 EUR is ~35 900 HUF, while
     * 16 000 EUR is only ~13 900 GBP.
     */
    #[DataProvider('antiFraudThresholdDataProvider')]
    public function testAntiFraudCheckIsBasedOnTheEuroValueOfTheTransfer(
        Currency $fromCurrency,
        Currency $toCurrency,
        float $rateToTarget,
        float $rateToEur,
        string $amount,
        bool $expectedAntiFraudCheck,
        TransactionStatus $expectedStatus,
    ): void {
        $fromWallet = Wallet::create(1, $fromCurrency);
        $fromWallet->setBalance(10_000_000.0);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, Wallet::create(1, $toCurrency)],
            ]);

        $this->exchangeRateService
            ->method('getExchangeRateBetween')
            ->willReturnMap([
                [$fromCurrency, $toCurrency, $rateToTarget],
                [$fromCurrency, Currency::EUR, $rateToEur],
            ]);

        $this->spreadService->method('calculateSpread')->willReturn('0.0000');

        $transaction = $this->transferService->transfer(1, 1, 2, $amount);

        self::assertSame($expectedAntiFraudCheck, $transaction->requiresAntiFraudCheck());
        self::assertSame($expectedStatus, $transaction->getStatus());
    }

    public static function antiFraudThresholdDataProvider(): Generator
    {
        // Used to be flagged because 3 590 000 HUF is above 15 000 — it is only 100 EUR.
        yield 'small EUR transfer into a low-value currency is not flagged' => [
            'fromCurrency' => Currency::EUR,
            'toCurrency' => Currency::HUF,
            'rateToTarget' => 359.2288,
            'rateToEur' => 1.0,
            'amount' => '100.00',
            'expectedAntiFraudCheck' => false,
            'expectedStatus' => TransactionStatus::PENDING,
        ];
        // Used to slip through because the resulting 13 900 GBP is below 15 000.
        yield 'large EUR transfer into a high-value currency is flagged' => [
            'fromCurrency' => Currency::EUR,
            'toCurrency' => Currency::GBP,
            'rateToTarget' => 0.8684,
            'rateToEur' => 1.0,
            'amount' => '16000.00',
            'expectedAntiFraudCheck' => true,
            'expectedStatus' => TransactionStatus::FRAUD_REVIEW,
        ];
        yield 'exactly at the threshold is not flagged' => [
            'fromCurrency' => Currency::EUR,
            'toCurrency' => Currency::USD,
            'rateToTarget' => 1.1624,
            'rateToEur' => 1.0,
            'amount' => '15000.00',
            'expectedAntiFraudCheck' => false,
            'expectedStatus' => TransactionStatus::PENDING,
        ];
        yield 'just above the threshold is flagged' => [
            'fromCurrency' => Currency::EUR,
            'toCurrency' => Currency::USD,
            'rateToTarget' => 1.1624,
            'rateToEur' => 1.0,
            'amount' => '15000.01',
            'expectedAntiFraudCheck' => true,
            'expectedStatus' => TransactionStatus::FRAUD_REVIEW,
        ];
        yield 'transfer in a low-value currency worth more than the threshold is flagged' => [
            'fromCurrency' => Currency::JPY,
            'toCurrency' => Currency::PLN,
            'rateToTarget' => 0.0229,
            'rateToEur' => 0.0054,
            'amount' => '3000000.00', // 16 200.00 EUR
            'expectedAntiFraudCheck' => true,
            'expectedStatus' => TransactionStatus::FRAUD_REVIEW,
        ];
    }

    public function testTransferThrowsWhenSourceAndTargetAreTheSameWallet(): void
    {
        $this->walletRepository->expects(self::never())->method('findById');
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(SameWalletTransferException::class);
        $this->expectExceptionMessage('Cannot transfer funds from wallet 1 to itself.');

        $this->transferService->transfer(1, 1, 1, '100.00');
    }

    public function testTransferThrowsWhenSourceWalletIsBlocked(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance(500.0);
        $fromWallet->setIsBlocked(true);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, Wallet::create(1, Currency::EUR)],
            ]);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletBlockedException::class);
        $this->expectExceptionMessage('Wallet 1 is blocked.');

        $this->transferService->transfer(1, 1, 2, '100.00');
    }

    public function testTransferThrowsWhenTargetWalletIsBlocked(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance(500.0);

        $toWallet = Wallet::create(1, Currency::EUR);
        $toWallet->setIsBlocked(true);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletBlockedException::class);
        $this->expectExceptionMessage('Wallet 2 is blocked.');

        $this->transferService->transfer(1, 1, 2, '100.00');
    }

    public function testTransferThrowsWhenBalanceIsTooLow(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance(99.99);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, Wallet::create(1, Currency::EUR)],
            ]);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(InsufficientFundsException::class);
        $this->expectExceptionMessage('Wallet 1 has insufficient funds.');

        $this->transferService->transfer(1, 1, 2, '100.00');
    }

    public function testTransferAllowsSpendingTheWholeBalance(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance(100.0);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, Wallet::create(1, Currency::EUR)],
            ]);

        $this->exchangeRateService->method('getExchangeRateBetween')->willReturn(0.25);
        $this->spreadService->method('calculateSpread')->willReturn('0.1300');

        $this->transactionRepository->expects(self::once())->method('save');

        $transaction = $this->transferService->transfer(1, 1, 2, '100.00');

        self::assertSame('24.8700', $transaction->getToAmount());
    }

    public function testTransferThrowsWhenFromWalletNotFound(): void
    {
        $this->walletRepository
            ->expects($this->once())
            ->method('findById')
            ->with(99)
            ->willReturn(null);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 99 not found.');

        $this->transferService->transfer(1, 99, 2, '100.00');
    }

    public function testTransferThrowsWhenToWalletNotFound(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [99, null],
            ]);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 99 not found.');

        $this->transferService->transfer(1, 1, 99, '100.00');
    }

    public function testTransferThrowsWhenFromWalletBelongsToOtherUser(): void
    {
        $fromWallet = Wallet::create(2, Currency::PLN);

        $this->walletRepository
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($fromWallet);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 1 not found.');

        $this->transferService->transfer(1, 1, 2, '100.00');
    }

    public function testTransferThrowsWhenToWalletBelongsToOtherUser(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $toWallet = Wallet::create(2, Currency::EUR);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 2 not found.');

        $this->transferService->transfer(1, 1, 2, '100.00');
    }
}
