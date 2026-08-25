<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260519120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deleted_at to wallets';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `wallets` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `last_activity_at`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `wallets` DROP COLUMN `deleted_at`');
    }
}
