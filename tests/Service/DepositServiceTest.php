<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\Exception\InvalidAmountException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Persistence\AtomicOperationRunnerInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\DepositService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DepositServiceTest extends TestCase
{
    private const float MAX_AMOUNT = 10000.0;

    private WalletRepositoryInterface&MockObject $walletRepository;
    private DepositService $depositService;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);

        $atomicOperationRunner = $this->createMock(AtomicOperationRunnerInterface::class);
        $atomicOperationRunner
            ->method('run')
            ->willReturnCallback(static fn (callable $operation): mixed => $operation());

        $this->depositService = new DepositService($this->walletRepository, $atomicOperationRunner, self::MAX_AMOUNT);
    }

    public function testDepositSuccessfully(): void
    {
        $userId = 1;
        $wallet = Wallet::create($userId, Currency::PLN);

        $this->walletRepository
            ->expects(self::once())
            ->method('findByIdForUpdate')
            ->with(1)
            ->willReturn($wallet);

        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with($wallet);

        $result = $this->depositService->deposit($userId, 1, '500.00');

        self::assertSame(500.0, $result->getBalance());
        self::assertNotNull($result->getLastActivityAt());
    }

    public function testDepositAddsToExistingBalance(): void
    {
        $userId = 1;
        $wallet = Wallet::create($userId, Currency::EUR);
        $wallet->setBalance(200.0);

        $this->walletRepository
            ->method('findByIdForUpdate')
            ->willReturn($wallet);

        $this->depositService->deposit($userId, 1, '300.00');

        self::assertSame(500.0, $wallet->getBalance());
    }

    public function testDepositLocksTheWalletInsideAnAtomicOperation(): void
    {
        $wallet = Wallet::create(1, Currency::PLN);
        $wallet->setBalance(200.0);

        $runner = $this->createMock(AtomicOperationRunnerInterface::class);
        $runner
            ->expects(self::once())
            ->method('run')
            ->willReturnCallback(static fn (callable $operation): mixed => $operation());

        $this->walletRepository
            ->expects(self::once())
            ->method('findByIdForUpdate')
            ->with(1)
            ->willReturn($wallet);

        // Reading the balance without a lock is what makes concurrent deposits lose money.
        $this->walletRepository->expects(self::never())->method('findById');

        $result = new DepositService($this->walletRepository, $runner, self::MAX_AMOUNT)->deposit(1, 1, '300.00');

        self::assertSame(500.0, $result->getBalance());
    }

    public function testDepositThrowsWhenAmountIsNotPositive(): void
    {
        $this->walletRepository->expects(self::never())->method('findByIdForUpdate');
        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(InvalidAmountException::class);
        $this->expectExceptionMessage('Amount must be a positive number.');

        $this->depositService->deposit(1, 1, '-100.00');
    }

    public function testDepositThrowsWhenAmountExceedsMaximum(): void
    {
        $this->walletRepository->expects(self::never())->method('findByIdForUpdate');
        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(InvalidAmountException::class);
        $this->expectExceptionMessage('Amount cannot exceed 10000.');

        $this->depositService->deposit(1, 1, '10000.01');
    }

    public function testDepositAcceptsTheMaximumAmount(): void
    {
        $wallet = Wallet::create(1, Currency::PLN);

        $this->walletRepository->method('findByIdForUpdate')->willReturn($wallet);

        $this->depositService->deposit(1, 1, (string) self::MAX_AMOUNT);

        self::assertSame(self::MAX_AMOUNT, $wallet->getBalance());
    }

    public function testDepositThrowsWhenWalletNotFound(): void
    {
        $this->walletRepository
            ->expects(self::once())
            ->method('findByIdForUpdate')
            ->with(99)
            ->willReturn(null);

        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 99 not found.');

        $this->depositService->deposit(1, 99, '100.00');
    }

    public function testDepositThrowsWhenWalletBelongsToOtherUser(): void
    {
        $wallet = Wallet::create(2, Currency::PLN);

        $this->walletRepository
            ->expects(self::once())
            ->method('findByIdForUpdate')
            ->with(1)
            ->willReturn($wallet);

        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 1 not found.');

        $this->depositService->deposit(1, 1, '100.00');
    }

    public function testDepositThrowsWhenWalletIsBlocked(): void
    {
        $userId = 1;
        $wallet = Wallet::create($userId, Currency::PLN);
        $wallet->setIsBlocked(true);

        $this->walletRepository
            ->expects(self::once())
            ->method('findByIdForUpdate')
            ->with(1)
            ->willReturn($wallet);

        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletBlockedException::class);
        $this->expectExceptionMessage('Wallet 1 is blocked.');

        $this->depositService->deposit($userId, 1, '100.00');
    }
}
