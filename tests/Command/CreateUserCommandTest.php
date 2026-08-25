<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CreateUserCommand;
use App\Entity\User;
use App\Entity\UserToken;
use App\Repository\UserRepositoryInterface;
use App\Repository\UserTokenRepositoryInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
class CreateUserCommandTest extends TestCase
{
    private UserRepositoryInterface&MockObject $userRepository;
    private UserTokenRepositoryInterface&MockObject $userTokenRepository;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->userTokenRepository = $this->createMock(UserTokenRepositoryInterface::class);

        $this->commandTester = new CommandTester(
            new CreateUserCommand($this->userRepository, $this->userTokenRepository),
        );
    }

    public function testItCreatesAUserWithATokenValidForAYear(): void
    {
        $savedUser = null;
        $savedToken = null;

        $this->userRepository
            ->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (User $user) use (&$savedUser): void {
                // The real repository assigns the id on insert.
                new ReflectionProperty($user, 'id')->setValue($user, 42);
                $savedUser = $user;
            });

        $this->userTokenRepository
            ->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (UserToken $token) use (&$savedToken): void {
                $savedToken = $token;
            });

        $exitCode = $this->commandTester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertInstanceOf(User::class, $savedUser);
        self::assertSame(['ROLE_USER'], $savedUser->getRoles());

        // The token has to belong to the user that was just persisted.
        self::assertInstanceOf(UserToken::class, $savedToken);
        self::assertSame(42, $savedToken->getUserId());
        self::assertTrue($savedToken->isValid());

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString($savedUser->getUserIdentifier(), $display);
        self::assertStringContainsString($savedToken->getToken(), $display);
    }
}
