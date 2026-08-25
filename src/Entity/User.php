<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Security\Core\User\UserInterface;

class User implements UserInterface
{
    /** @param list<string> $roles */
    public function __construct(
        private ?int $id,
        private readonly string $email,
        private array $roles,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdNotNull(): int
    {
        if (null === $this->id) {
            throw new RuntimeException('User has no ID');
        }

        return $this->id;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
