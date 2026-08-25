<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ShowCompanyWalletCommand;
use App\Entity\CompanyWallet;
use App\Enum\Currency;
use App\Repository\CompanyWalletRepositoryInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
class ShowCompanyWalletCommandTest extends TestCase
{
    private CompanyWalletRepositoryInterface&MockObject $companyWalletRepository;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->companyWalletRepository = $this->createMock(CompanyWalletRepositoryInterface::class);

        $this->commandTester = new CommandTester(
            new ShowCompanyWalletCommand($this->companyWalletRepository),
        );
    }

    public function testItReportsWhenNothingWasEarnedYet(): void
    {
        $this->companyWalletRepository->method('findAll')->willReturn([]);

        $exitCode = $this->commandTester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No spread earnings recorded yet.', $this->commandTester->getDisplay());
    }

    public function testItListsEarningsPerCurrency(): void
    {
        $eur = CompanyWallet::create(Currency::EUR);
        $eur->setBalance(1.5727);

        $usd = CompanyWallet::create(Currency::USD);
        $usd->setBalance(230.5);

        $this->companyWalletRepository->method('findAll')->willReturn([$eur, $usd]);

        $this->commandTester->execute([]);

        $display = $this->commandTester->getDisplay();

        // Earnings are reported with the same scale they are stored with.
        self::assertStringContainsString('1.5727', $display);
        self::assertStringContainsString('230.5000', $display);
        self::assertStringContainsString('EUR', $display);
        self::assertStringContainsString('USD', $display);
    }
}
