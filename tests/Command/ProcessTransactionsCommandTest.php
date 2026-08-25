<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ProcessTransactionsCommand;
use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Persistence\AtomicOperationRunnerInterface;
use App\Repository\CompanyWalletRepositoryInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\TransactionProcessorService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
class ProcessTransactionsCommandTest extends TestCase
{
    private TransactionRepositoryInterface $transactionRepository;
    private WalletRepositoryInterface $walletRepository;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);

        $command = new ProcessTransactionsCommand(
            $this->transactionRepository,
            new TransactionProcessorService(
                $this->walletRepository,
                $this->transactionRepository,
                $this->createMock(CompanyWalletRepositoryInterface::class),
                $this->atomicOperationRunner(),
            ),
        );

        $this->commandTester = new CommandTester($command);
    }

    public function testPendingTransactionsAreCompletedWithoutAnyQuestion(): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->stubWallets();
        $this->stubTransactions(pending: [$transaction], fraudReview: []);

        $exitCode = $this->commandTester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
    }

    public function testFraudReviewTransactionIsCompletedWhenApproved(): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: true);

        $this->stubWallets();
        $this->stubTransactions(pending: [], fraudReview: [$transaction]);

        $this->commandTester->setInputs(['yes']);
        $this->commandTester->execute([]);

        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
        self::assertNotNull($transaction->getAntiFraudCheckedAt());
    }

    public function testFraudReviewTransactionIsRejectedWhenDeclined(): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: true);

        $this->stubWallets();
        $this->stubTransactions(pending: [], fraudReview: [$transaction]);

        $this->commandTester->setInputs(['no']);
        $this->commandTester->execute([]);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertNotNull($transaction->getAntiFraudCheckedAt());
    }

    /**
     * Nobody can answer the question without a terminal, so the transaction has to wait
     * instead of being approved by the default answer.
     */
    public function testFraudReviewTransactionIsLeftUntouchedWhenRunNonInteractively(): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: true);

        $this->stubWallets();
        $this->stubTransactions(pending: [], fraudReview: [$transaction]);

        $this->walletRepository->expects(self::never())->method('save');

        $exitCode = $this->commandTester->execute([], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(TransactionStatus::FRAUD_REVIEW, $transaction->getStatus());
        self::assertNull($transaction->getAntiFraudCheckedAt());
        self::assertStringContainsString('skipped', $this->commandTester->getDisplay());
    }

    public function testPendingTransactionsAreStillProcessedNonInteractively(): void
    {
        $pending = $this->makeTransaction(requiresAntiFraudCheck: false);
        $fraudReview = $this->makeTransaction(requiresAntiFraudCheck: true);

        $this->stubWallets();
        $this->stubTransactions(pending: [$pending], fraudReview: [$fraudReview]);

        $this->commandTester->execute([], ['interactive' => false]);

        self::assertSame(TransactionStatus::COMPLETED, $pending->getStatus());
        self::assertSame(TransactionStatus::FRAUD_REVIEW, $fraudReview->getStatus());
    }

    /** Runs the settlement inline, the way a database transaction would. */
    private function atomicOperationRunner(): AtomicOperationRunnerInterface
    {
        $runner = $this->createMock(AtomicOperationRunnerInterface::class);
        $runner
            ->method('run')
            ->willReturnCallback(static fn (callable $operation): mixed => $operation());

        return $runner;
    }

    /**
     * @param Transaction[] $pending
     * @param Transaction[] $fraudReview
     */
    private function stubTransactions(array $pending, array $fraudReview): void
    {
        $this->transactionRepository
            ->method('findByStatus')
            ->willReturnMap([
                [TransactionStatus::PENDING, $pending],
                [TransactionStatus::FRAUD_REVIEW, $fraudReview],
            ]);
    }

    private function stubWallets(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance(1000.0);

        $toWallet = Wallet::create(1, Currency::EUR);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);
    }

    private function makeTransaction(bool $requiresAntiFraudCheck): Transaction
    {
        $transaction = Transaction::create(
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: '100.0000',
            toAmount: '25.0000',
            fromCurrency: Currency::PLN,
            toCurrency: Currency::EUR,
            spread: '0.5000',
            exchangeRate: '0.250000',
            requiresAntiFraudCheck: $requiresAntiFraudCheck,
        );

        // The command prints the transaction id, which only exists once it is persisted.
        new ReflectionProperty($transaction, 'id')->setValue($transaction, 1);

        return $transaction;
    }
}
