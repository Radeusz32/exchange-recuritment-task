<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\WalletController;
use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\InsufficientFundsException;
use App\Exception\InvalidAmountException;
use App\Exception\SameWalletTransferException;
use App\Exception\WalletAlreadyExistsException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletHasPendingTransactionsException;
use App\Exception\WalletNotEmptyException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\DepositService;
use App\Service\TransferService;
use App\Service\WalletService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

#[AllowMockObjectsWithoutExpectations]
class WalletControllerTest extends TestCase
{
    private WalletService $walletService;
    private WalletRepositoryInterface $walletRepository;
    private TransferService $transferService;
    private DepositService $depositService;
    private TransactionRepositoryInterface $transactionRepository;
    private WalletController $controller;

    protected function setUp(): void
    {
        $this->walletService = $this->createMock(WalletService::class);
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transferService = $this->createMock(TransferService::class);
        $this->depositService = $this->createMock(DepositService::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);

        $this->controller = new WalletController(
            $this->walletService,
            $this->walletRepository,
            $this->transferService,
            $this->depositService,
            $this->transactionRepository,
        );
    }

    /**
     * @throws Throwable
     */
    public function testListReturnsWallets(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());
        $wallet1 = Wallet::create(1, Currency::PLN);
        $wallet1->setBalance(150.5);

        $wallet2 = Wallet::create(1, Currency::EUR);
        $wallet2->setBalance(20.25);

        $this->walletRepository
            ->expects(self::once())
            ->method('findByUserId')
            ->with(1)
            ->willReturn([$wallet1, $wallet2]);

        $response = $this->controller->list($user);

        self::assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $data);
        self::assertSame(['PLN', 'EUR'], array_column($data, 'currency'));
        self::assertSame([150.5, 20.25], array_column($data, 'balance'));
    }

    /**
     * @throws Throwable
     */
    public function testCreateWalletSuccessfully(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());
        $wallet = Wallet::create(1, Currency::USD);

        $this->walletService
            ->expects(self::once())
            ->method('createWallet')
            ->with(1, Currency::USD)
            ->willReturn($wallet);

        $request = new Request(content: json_encode(['currency' => 'USD'], JSON_THROW_ON_ERROR));
        $response = $this->controller->create($request, $user);

        self::assertSame(201, $response->getStatusCode());
    }

    /**
     * @throws Throwable
     */
    public function testCreateReturnsBadRequestWhenCurrencyMissing(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode([], JSON_THROW_ON_ERROR));
        $response = $this->controller->create($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
    }

    /**
     * @throws Throwable
     */
    public function testCreateReturnsBadRequestWhenCurrencyInvalid(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode(['currency' => 'INVALID'], JSON_THROW_ON_ERROR));
        $response = $this->controller->create($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Invalid currency.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testCreateReturnsConflictWhenWalletAlreadyExists(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->walletService
            ->method('createWallet')
            ->willThrowException(new WalletAlreadyExistsException(1, Currency::PLN));

        $request = new Request(content: json_encode(['currency' => 'PLN'], JSON_THROW_ON_ERROR));
        $response = $this->controller->create($request, $user);

        self::assertSame(409, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Wallet for user 1 in currency PLN already exists.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testTransferSuccessfully(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());
        $transaction = new Transaction(
            id: 42,
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: '100.0000',
            toAmount: '25.1234',
            fromCurrency: Currency::PLN,
            toCurrency: Currency::EUR,
            spread: '0.1260',
            exchangeRate: '0.250000',
            status: TransactionStatus::PENDING,
            requiresAntiFraudCheck: false,
            antiFraudCheckedAt: null,
            createdAt: new DateTimeImmutable(),
        );

        $this->transferService
            ->expects(self::once())
            ->method('transfer')
            ->with(1, 1, 2, '100.00')
            ->willReturn($transaction);

        $request = new Request(content: json_encode([
            'fromWalletId' => 1,
            'toWalletId' => 2,
            'amount' => '100.00',
        ], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(201, $response->getStatusCode());
    }

    /**
     * @throws Throwable
     */
    public function testTransferReturnsBadRequestWhenFromWalletIdMissing(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode(['toWalletId' => 2, 'amount' => '100.00'], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
    }

    /**
     * @throws Throwable
     */
    public function testTransferReturnsBadRequestWhenToWalletIdMissing(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode(['fromWalletId' => 1, 'amount' => '100.00'], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
    }

    /**
     * @throws Throwable
     */
    public function testTransferReturnsBadRequestWhenAmountMissing(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode(['fromWalletId' => 1, 'toWalletId' => 2], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
    }

    /**
     * @throws Throwable
     */
    public function testTransferReturnsBadRequestWhenAmountInvalid(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode([
            'fromWalletId' => 1,
            'toWalletId' => 2,
            'amount' => '-50',
        ], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Amount must be a positive number.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testTransferReturnsNotFoundWhenWalletNotFound(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->transferService
            ->method('transfer')
            ->willThrowException(new WalletNotFoundException(99));

        $request = new Request(content: json_encode([
            'fromWalletId' => 99,
            'toWalletId' => 2,
            'amount' => '100.00',
        ], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(404, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Wallet 99 not found.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testTransferReturnsUnprocessableWhenBalanceIsTooLow(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->transferService
            ->method('transfer')
            ->willThrowException(new InsufficientFundsException(1));

        $request = new Request(content: json_encode([
            'fromWalletId' => 1,
            'toWalletId' => 2,
            'amount' => '100.00',
        ], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Wallet 1 has insufficient funds.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testTransferReturnsUnprocessableWhenWalletBlocked(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->transferService
            ->method('transfer')
            ->willThrowException(new WalletBlockedException(2));

        $request = new Request(content: json_encode([
            'fromWalletId' => 1,
            'toWalletId' => 2,
            'amount' => '100.00',
        ], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Wallet 2 is blocked.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testTransferReturnsUnprocessableForTheSameWallet(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->transferService
            ->method('transfer')
            ->willThrowException(new SameWalletTransferException(1));

        $request = new Request(content: json_encode([
            'fromWalletId' => 1,
            'toWalletId' => 1,
            'amount' => '100.00',
        ], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Cannot transfer funds from wallet 1 to itself.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testDepositSuccessfully(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());
        $wallet = Wallet::create(1, Currency::PLN);

        $this->depositService
            ->expects(self::once())
            ->method('deposit')
            ->with(1, 5, '500.00')
            ->willReturn($wallet);

        $request = new Request(content: json_encode(['amount' => '500.00'], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @throws Throwable
     */
    public function testDepositReturnsBadRequestWhenAmountMissing(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode([], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
    }

    /**
     * @throws Throwable
     */
    public function testDepositReturnsBadRequestWhenAmountInvalid(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode(['amount' => '-50'], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Amount must be a positive number.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testDepositReturnsBadRequestWhenAmountExceedsMax(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->depositService
            ->method('deposit')
            ->willThrowException(InvalidAmountException::exceedsMaximum(10000.0));

        $request = new Request(content: json_encode(['amount' => '99999'], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Amount cannot exceed 10000.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testDepositReturnsBadRequestForMalformedJson(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->depositService->expects(self::never())->method('deposit');

        $request = new Request(content: '{"amount": ');
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Invalid JSON body.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testCreateReturnsBadRequestForMalformedJson(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->walletService->expects(self::never())->method('createWallet');

        $request = new Request(content: 'not json at all');
        $response = $this->controller->create($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Invalid JSON body.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testTransferReturnsBadRequestForMalformedJson(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->transferService->expects(self::never())->method('transfer');

        $request = new Request(content: '{"fromWalletId": 1,}');
        $response = $this->controller->transfer($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Invalid JSON body.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testEmptyBodyIsReportedAsMissingField(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $response = $this->controller->create(new Request(), $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Missing required field: currency.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testDepositReturnsNotFoundWhenWalletNotFound(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->depositService
            ->method('deposit')
            ->willThrowException(new WalletNotFoundException(99));

        $request = new Request(content: json_encode(['amount' => '100.00'], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(99, $request, $user);

        self::assertSame(404, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Wallet 99 not found.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testDepositReturnsUnprocessableWhenWalletBlocked(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->depositService
            ->method('deposit')
            ->willThrowException(new WalletBlockedException(5));

        $request = new Request(content: json_encode(['amount' => '100.00'], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Wallet 5 is blocked.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testTransactionsReturnsTheUsersHistory(): void
    {
        $user = new User(7, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->transactionRepository
            ->expects(self::once())
            ->method('findByUserId')
            ->with(7, null)
            ->willReturn([$this->makeTransaction(1, TransactionStatus::COMPLETED), $this->makeTransaction(2, TransactionStatus::FRAUD_REVIEW)]);

        $response = $this->controller->transactions(new Request(), $user);

        self::assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $data);
        self::assertSame([1, 2], array_column($data, 'id'));
        self::assertSame(['completed', 'fraud_review'], array_column($data, 'status'));
    }

    /**
     * @throws Throwable
     */
    public function testTransactionsCanBeNarrowedDownToASingleWallet(): void
    {
        $user = new User(7, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->transactionRepository
            ->expects(self::once())
            ->method('findByUserId')
            ->with(7, 5)
            ->willReturn([]);

        $response = $this->controller->transactions(new Request(query: ['walletId' => '5']), $user);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('[]', $response->getContent());
    }

    /**
     * @throws Throwable
     */
    public function testTransactionsRejectsANonNumericWalletId(): void
    {
        $user = new User(7, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->transactionRepository->expects(self::never())->method('findByUserId');

        $response = $this->controller->transactions(new Request(query: ['walletId' => 'abc']), $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('walletId must be a positive integer.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testDeleteWalletReturnsNoContent(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->walletService
            ->expects(self::once())
            ->method('deleteWallet')
            ->with(1, 5);

        $response = $this->controller->delete(5, $user);

        self::assertSame(204, $response->getStatusCode());
        self::assertEmpty($response->getContent());
    }

    /**
     * @throws Throwable
     */
    public function testDeleteWalletReturnsNotFoundForAnotherUsersWallet(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->walletService
            ->method('deleteWallet')
            ->willThrowException(new WalletNotFoundException(5));

        $response = $this->controller->delete(5, $user);

        self::assertSame(404, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Wallet 5 not found.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testDeleteWalletReturnsConflictWhenItStillHoldsFunds(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->walletService
            ->method('deleteWallet')
            ->willThrowException(new WalletNotEmptyException(5));

        $response = $this->controller->delete(5, $user);

        self::assertSame(409, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Wallet 5 still holds funds and cannot be deleted.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testDeleteWalletReturnsConflictWhenTransactionsAwaitProcessing(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->walletService
            ->method('deleteWallet')
            ->willThrowException(new WalletHasPendingTransactionsException(5));

        $response = $this->controller->delete(5, $user);

        self::assertSame(409, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Wallet 5 has transactions awaiting processing and cannot be deleted.', $data['error']);
    }

    private function makeTransaction(int $id, TransactionStatus $status): Transaction
    {
        return new Transaction(
            id: $id,
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: '100.0000',
            toAmount: '25.1234',
            fromCurrency: Currency::PLN,
            toCurrency: Currency::EUR,
            spread: '0.1260',
            exchangeRate: '0.250000',
            status: $status,
            requiresAntiFraudCheck: TransactionStatus::FRAUD_REVIEW === $status,
            antiFraudCheckedAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }
}
