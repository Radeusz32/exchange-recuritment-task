<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\User;
use App\Entity\UserToken;
use App\Repository\UserRepositoryInterface;
use App\Repository\UserTokenRepositoryInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Runs the real application against a real database, which is the only way to cover the
 * SQL in the repositories - unit tests mock it away. The whole class is skipped when no
 * database is reachable, so the suite still passes without the containers running.
 */
abstract class IntegrationTestCase extends KernelTestCase
{
    private const array TABLES = ['transactions', 'wallets', 'user_tokens', 'users', 'company_wallets'];

    private static bool $schemaReady = false;

    public static function setUpBeforeClass(): void
    {
        if (self::$schemaReady) {
            return;
        }

        try {
            self::prepareDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped('No database available for integration tests: '.$e->getMessage());
        }

        self::$schemaReady = true;
    }

    protected function setUp(): void
    {
        $connection = $this->connection();

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        foreach (self::TABLES as $table) {
            $connection->executeStatement(sprintf('TRUNCATE TABLE %s', $table));
        }

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function connection(): Connection
    {
        if (!static::$booted) {
            self::bootKernel();
        }

        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    protected function service(string $id): object
    {
        if (!static::$booted) {
            self::bootKernel();
        }

        return self::getContainer()->get($id);
    }

    /**
     * Sends a real request through the whole stack, security included.
     *
     * @param array<string, mixed>|null $body
     */
    protected function http(string $method, string $uri, ?string $token = null, ?array $body = null): Response
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        // A fresh kernel per request, so nothing authenticated earlier leaks into the next one.
        self::ensureKernelShutdown();
        $kernel = self::bootKernel();

        return $kernel->handle(Request::create(
            $uri,
            $method,
            server: $server,
            content: null !== $body ? json_encode($body, JSON_THROW_ON_ERROR) : null,
        ));
    }

    /** @return array{0: User, 1: string} the user and the bearer token to authenticate as */
    protected function createUserWithToken(): array
    {
        /** @var UserRepositoryInterface $users */
        $users = $this->service(UserRepositoryInterface::class);
        /** @var UserTokenRepositoryInterface $tokens */
        $tokens = $this->service(UserTokenRepositoryInterface::class);

        $user = new User(
            id: null,
            email: bin2hex(random_bytes(8)).'@example.com',
            roles: ['ROLE_USER'],
            createdAt: new DateTimeImmutable(),
        );
        $users->save($user);

        $token = UserToken::create($user->getIdNotNull(), new DateTimeImmutable('+1 day'));
        $tokens->save($token);

        return [$user, $token->getToken()];
    }

    /** @return array<mixed> */
    protected function jsonOf(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function prepareDatabase(): void
    {
        self::ensureKernelShutdown();
        $kernel = self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $params = $connection->getParams();
        $databaseName = $params['dbname'];
        unset($params['dbname']);

        // Connect without a database first: it may not exist yet.
        $server = DriverManager::getConnection($params);
        $server->executeStatement(sprintf('CREATE DATABASE IF NOT EXISTS %s', $databaseName));

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $exitCode = $application->run(
            new ArrayInput(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]),
            new NullOutput(),
        );

        if (0 !== $exitCode) {
            throw new RuntimeException('Migrations failed for the test database.');
        }
    }
}
