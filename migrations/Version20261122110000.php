<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index ledger events by kind and position so a read of one kind does not scan the ledger';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX ledger_event_kind ON ledger_event (kind, position)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX ledger_event_kind');
    }
}
