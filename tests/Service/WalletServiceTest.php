<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\WalletAlreadyExistsException;
use App\Exception\WalletHasPendingTransactionsException;
use App\Exception\WalletNotEmptyException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\WalletService;
use Generator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class WalletServiceTest extends TestCase
{
    private WalletRepositoryInterface $walletRepository;
    private TransactionRepositoryInterface $transactionRepository;
    private WalletService $walletService;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->walletService = new WalletService($this->walletRepository, $this->transactionRepository);
    }

    public function testCreateWalletSuccessfully(): void
    {
        $userId = 1;
        $currency = Currency::EUR;

        $this->walletRepository
            ->expects(self::once())
            ->method('findByUserIdAndCurrency')
            ->with($userId, $currency)
            ->willReturn(null);

        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with($this->isInstanceOf(Wallet::class));

        $wallet = $this->walletService->createWallet($userId, $currency);

        self::assertSame($userId, $wallet->getUserId());
        self::assertSame($currency, $wallet->getCurrency());
        self::assertSame(0.0, $wallet->getBalance());
        self::assertFalse($wallet->isBlocked());
    }

    public function testCreateWalletThrowsWhenWalletAlreadyExists(): void
    {
        $userId = 1;
        $currency = Currency::PLN;

        $existingWallet = Wallet::create($userId, $currency);

        $this->walletRepository
            ->expects(self::once())
            ->method('findByUserIdAndCurrency')
            ->with($userId, $currency)
            ->willReturn($existingWallet);

        $this->walletRepository
            ->expects(self::never())
            ->method('save');

        $this->expectException(WalletAlreadyExistsException::class);
        $this->expectExceptionMessage('Wallet for user 1 in currency PLN already exists.');

        $this->walletService->createWallet($userId, $currency);
    }

    public function testCreateWalletRestoresAPreviouslyDeletedWallet(): void
    {
        $deletedWallet = Wallet::create(1, Currency::PLN);
        $deletedWallet->delete();

        $this->walletRepository
            ->expects(self::once())
            ->method('findByUserIdAndCurrency')
            ->with(1, Currency::PLN)
            ->willReturn($deletedWallet);

        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with($deletedWallet);

        $wallet = $this->walletService->createWallet(1, Currency::PLN);

        self::assertSame($deletedWallet, $wallet);
        self::assertFalse($wallet->isDeleted());
    }

    public function testDeleteWalletMarksItAsDeleted(): void
    {
        $wallet = Wallet::create(1, Currency::PLN);

        $this->walletRepository
            ->expects(self::once())
            ->method('findById')
            ->with(7)
            ->willReturn($wallet);

        $this->transactionRepository
            ->expects(self::once())
            ->method('findByWalletId')
            ->with(7)
            ->willReturn([]);

        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with($wallet);

        $this->walletService->deleteWallet(1, 7);

        self::assertTrue($wallet->isDeleted());
        self::assertNotNull($wallet->getDeletedAt());
    }

    public function testDeleteWalletIgnoresSettledTransactions(): void
    {
        $wallet = Wallet::create(1, Currency::PLN);

        $this->walletRepository->method('findById')->willReturn($wallet);

        $this->transactionRepository
            ->method('findByWalletId')
            ->willReturn([
                $this->makeTransaction(TransactionStatus::COMPLETED),
                $this->makeTransaction(TransactionStatus::REJECTED),
            ]);

        $this->walletService->deleteWallet(1, 7);

        self::assertTrue($wallet->isDeleted());
    }

    public function testDeleteWalletThrowsWhenWalletNotFound(): void
    {
        $this->walletRepository
            ->expects(self::once())
            ->method('findById')
            ->with(99)
            ->willReturn(null);

        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 99 not found.');

        $this->walletService->deleteWallet(1, 99);
    }

    public function testDeleteWalletThrowsWhenWalletBelongsToOtherUser(): void
    {
        $this->walletRepository
            ->method('findById')
            ->willReturn(Wallet::create(2, Currency::PLN));

        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 7 not found.');

        $this->walletService->deleteWallet(1, 7);
    }

    public function testDeleteWalletThrowsWhenItStillHoldsFunds(): void
    {
        $wallet = Wallet::create(1, Currency::PLN);
        $wallet->setBalance(0.01);

        $this->walletRepository->method('findById')->willReturn($wallet);

        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotEmptyException::class);
        $this->expectExceptionMessage('Wallet 7 still holds funds and cannot be deleted.');

        $this->walletService->deleteWallet(1, 7);
    }

    #[DataProvider('unprocessedStatusDataProvider')]
    public function testDeleteWalletThrowsWhenTransactionsAwaitProcessing(TransactionStatus $status): void
    {
        $wallet = Wallet::create(1, Currency::PLN);

        $this->walletRepository->method('findById')->willReturn($wallet);

        $this->transactionRepository
            ->method('findByWalletId')
            ->willReturn([
                $this->makeTransaction(TransactionStatus::COMPLETED),
                $this->makeTransaction($status),
            ]);

        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletHasPendingTransactionsException::class);
        $this->expectExceptionMessage('Wallet 7 has transactions awaiting processing and cannot be deleted.');

        $this->walletService->deleteWallet(1, 7);
    }

    public static function unprocessedStatusDataProvider(): Generator
    {
        yield 'pending' => ['status' => TransactionStatus::PENDING];
        yield 'awaiting fraud review' => ['status' => TransactionStatus::FRAUD_REVIEW];
    }

    private function makeTransaction(TransactionStatus $status): Transaction
    {
        $transaction = Transaction::create(
            fromWalletId: 7,
            toWalletId: 8,
            fromAmount: '100.0000',
            toAmount: '25.0000',
            fromCurrency: Currency::PLN,
            toCurrency: Currency::EUR,
            spread: '0.5000',
            exchangeRate: '0.250000',
            requiresAntiFraudCheck: TransactionStatus::FRAUD_REVIEW === $status,
        );
        $transaction->setStatus($status);

        return $transaction;
    }
}
