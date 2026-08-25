<?php

declare(strict_types=1);

namespace App\Persistence;

interface AtomicOperationRunnerInterface
{
    /**
     * Runs the operation so that either all of its writes are persisted, or none of them.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function run(callable $operation): mixed;
}
