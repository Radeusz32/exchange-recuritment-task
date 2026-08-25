<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\TransactionStatus;
use App\Repository\TransactionRepositoryInterface;
use App\Service\TransactionProcessorService;

class WalletLifecycleTest extends IntegrationTestCase
{
    public function testMoneyMovesOnlyWhenTheTransactionIsSettled(): void
    {
        [, $token] = $this->createUserWithToken();

        $pln = $this->jsonOf($this->http('POST', '/api/wallets', $token, ['currency' => 'PLN']));
        $eur = $this->jsonOf($this->http('POST', '/api/wallets', $token, ['currency' => 'EUR']));

        $this->http('POST', sprintf('/api/wallets/%d/deposit', $pln['id']), $token, ['amount' => '1000.00']);

        $transaction = $this->jsonOf($this->http('POST', '/api/wallets/transfer', $token, [
            'fromWalletId' => $pln['id'],
            'toWalletId' => $eur['id'],
            'amount' => '400.00',
        ]));

        self::assertSame('pending', $transaction['status']);

        // Recording a transfer must not touch the balances.
        self::assertSame(1000.0, $this->balanceOf($pln['id']));
        self::assertSame(0.0, $this->balanceOf($eur['id']));

        $this->settlePendingTransactions();

        self::assertSame(600.0, $this->balanceOf($pln['id']));
        self::assertSame((float) $transaction['toAmount'], $this->balanceOf($eur['id']));

        // The spread is the company's earning, in the currency the client was paid in.
        self::assertSame(
            (float) $transaction['spread'],
            (float) $this->connection()->fetchOne("SELECT balance FROM company_wallets WHERE currency = 'EUR'"),
        );

        $history = $this->jsonOf($this->http('GET', '/api/wallets/transactions', $token));
        self::assertSame('completed', $history[0]['status']);
    }

    public function testATransferOverTheBalanceIsRefused(): void
    {
        [, $token] = $this->createUserWithToken();

        $pln = $this->jsonOf($this->http('POST', '/api/wallets', $token, ['currency' => 'PLN']));
        $eur = $this->jsonOf($this->http('POST', '/api/wallets', $token, ['currency' => 'EUR']));

        $this->http('POST', sprintf('/api/wallets/%d/deposit', $pln['id']), $token, ['amount' => '100.00']);

        $response = $this->http('POST', '/api/wallets/transfer', $token, [
            'fromWalletId' => $pln['id'],
            'toWalletId' => $eur['id'],
            'amount' => '100.01',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(100.0, $this->balanceOf($pln['id']));
    }

    public function testDeletedWalletDisappearsFromTheApiButKeepsItsRow(): void
    {
        [, $token] = $this->createUserWithToken();

        $usd = $this->jsonOf($this->http('POST', '/api/wallets', $token, ['currency' => 'USD']));

        self::assertSame(204, $this->http('DELETE', '/api/wallets/'.$usd['id'], $token)->getStatusCode());

        $wallets = $this->jsonOf($this->http('GET', '/api/wallets', $token));
        self::assertSame([], $wallets);

        // Soft delete: the row survives, so transactions referencing it keep their history.
        self::assertNotNull(
            $this->connection()->fetchOne('SELECT deleted_at FROM wallets WHERE id = ?', [$usd['id']]),
        );

        // Recreating the same currency restores that very row instead of failing on the unique key.
        $recreated = $this->jsonOf($this->http('POST', '/api/wallets', $token, ['currency' => 'USD']));
        self::assertSame($usd['id'], $recreated['id']);
        self::assertNull(
            $this->connection()->fetchOne('SELECT deleted_at FROM wallets WHERE id = ?', [$usd['id']]),
        );
    }

    public function testAWalletHoldingFundsCannotBeDeleted(): void
    {
        [, $token] = $this->createUserWithToken();

        $pln = $this->jsonOf($this->http('POST', '/api/wallets', $token, ['currency' => 'PLN']));
        $this->http('POST', sprintf('/api/wallets/%d/deposit', $pln['id']), $token, ['amount' => '10.00']);

        $response = $this->http('DELETE', '/api/wallets/'.$pln['id'], $token);

        self::assertSame(409, $response->getStatusCode());
        self::assertNull($this->connection()->fetchOne('SELECT deleted_at FROM wallets WHERE id = ?', [$pln['id']]));
    }

    public function testOneClientCannotSeeOrTouchAnotherClientsWallet(): void
    {
        [, $ownerToken] = $this->createUserWithToken();
        [, $strangerToken] = $this->createUserWithToken();

        $wallet = $this->jsonOf($this->http('POST', '/api/wallets', $ownerToken, ['currency' => 'PLN']));

        self::assertSame([], $this->jsonOf($this->http('GET', '/api/wallets', $strangerToken)));
        self::assertSame(404, $this->http('DELETE', '/api/wallets/'.$wallet['id'], $strangerToken)->getStatusCode());
        self::assertSame(404, $this->http(
            'POST',
            sprintf('/api/wallets/%d/deposit', $wallet['id']),
            $strangerToken,
            ['amount' => '10.00'],
        )->getStatusCode());
        self::assertSame([], $this->jsonOf($this->http('GET', '/api/wallets/transactions?walletId='.$wallet['id'], $strangerToken)));
    }

    public function testTheApiRefusesRequestsWithoutAToken(): void
    {
        $response = $this->http('GET', '/api/wallets');

        self::assertSame(401, $response->getStatusCode());
        self::assertArrayHasKey('error', $this->jsonOf($response));
    }

    private function balanceOf(int $walletId): float
    {
        return (float) $this->connection()->fetchOne('SELECT balance FROM wallets WHERE id = ?', [$walletId]);
    }

    private function settlePendingTransactions(): void
    {
        /** @var TransactionRepositoryInterface $transactions */
        $transactions = $this->service(TransactionRepositoryInterface::class);
        /** @var TransactionProcessorService $processor */
        $processor = $this->service(TransactionProcessorService::class);

        foreach ($transactions->findByStatus(TransactionStatus::PENDING) as $transaction) {
            $processor->complete($transaction);
        }
    }
}
