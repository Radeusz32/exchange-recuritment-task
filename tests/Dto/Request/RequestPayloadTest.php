<?php

declare(strict_types=1);

namespace App\Tests\Dto\Request;

use App\Dto\Request\CreateWalletRequest;
use App\Dto\Request\DepositRequest;
use App\Dto\Request\TransferRequest;
use App\Enum\Currency;
use App\Exception\InvalidRequestException;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RequestPayloadTest extends TestCase
{
    public function testCreateWalletReadsTheCurrency(): void
    {
        $payload = CreateWalletRequest::fromArray(['currency' => 'EUR']);

        self::assertSame(Currency::EUR, $payload->currency);
    }

    public function testCreateWalletRejectsAnUnknownCurrency(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Invalid currency.');

        CreateWalletRequest::fromArray(['currency' => 'XYZ']);
    }

    public function testCreateWalletRejectsAMissingCurrency(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Missing required field: currency.');

        CreateWalletRequest::fromArray([]);
    }

    public function testDepositKeepsTheAmountAsAString(): void
    {
        // Casting to float on the way in would already cost precision.
        $payload = DepositRequest::fromArray(['amount' => '500.1234']);

        self::assertSame('500.1234', $payload->amount);
    }

    public function testTransferReadsAllFields(): void
    {
        $payload = TransferRequest::fromArray([
            'fromWalletId' => '1',
            'toWalletId' => 2,
            'amount' => '100.00',
        ]);

        self::assertSame(1, $payload->fromWalletId);
        self::assertSame(2, $payload->toWalletId);
        self::assertSame('100.00', $payload->amount);
    }

    #[DataProvider('missingTransferFieldDataProvider')]
    public function testTransferRejectsAMissingField(array $data, string $expectedMessage): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage($expectedMessage);

        TransferRequest::fromArray($data);
    }

    public static function missingTransferFieldDataProvider(): Generator
    {
        yield 'no source wallet' => [
            'data' => ['toWalletId' => 2, 'amount' => '100.00'],
            'expectedMessage' => 'Missing required field: fromWalletId.',
        ];
        yield 'no target wallet' => [
            'data' => ['fromWalletId' => 1, 'amount' => '100.00'],
            'expectedMessage' => 'Missing required field: toWalletId.',
        ];
        yield 'no amount' => [
            'data' => ['fromWalletId' => 1, 'toWalletId' => 2],
            'expectedMessage' => 'Missing required field: amount.',
        ];
    }

    #[DataProvider('invalidAmountDataProvider')]
    public function testAmountsThatAreNotPositiveNumbersAreRejected(mixed $amount): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Amount must be a positive number.');

        DepositRequest::fromArray(['amount' => $amount]);
    }

    public static function invalidAmountDataProvider(): Generator
    {
        yield 'negative' => ['amount' => '-50'];
        yield 'zero' => ['amount' => '0'];
        yield 'text' => ['amount' => 'a lot'];
        yield 'array' => ['amount' => ['100.00']];
        yield 'boolean' => ['amount' => true];
    }
}
