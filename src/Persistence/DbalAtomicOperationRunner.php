<?php

declare(strict_types=1);

namespace App\Persistence;

use Closure;
use Doctrine\DBAL\Connection;

final readonly class DbalAtomicOperationRunner implements AtomicOperationRunnerInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function run(callable $operation): mixed
    {
        return $this->connection->transactional(Closure::fromCallable($operation));
    }
}
