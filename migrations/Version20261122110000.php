<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122110000 extends AbstractMigration
{
    private const string INDEX = 'ledger_event_subject';

    public function getDescription(): string
    {
        return 'Give ledger events an indexed subject so the events of one kind and subject are read without a scan of the ledger';
    }

    // A plain ADD COLUMN, because the schema diff rebuilds an SQLite table to alter it, which drops the append-only triggers; rows stored before keep no subject, since ledger rows are never back-filled.
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ledger_event ADD subject VARCHAR(255) DEFAULT NULL');
        $this->addSql($this->platform->getCreateIndexSQL(Index::editor()->setUnquotedName(self::INDEX)->setUnquotedColumnNames('kind', 'subject', 'position')->create(), 'ledger_event'));
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropIndexSQL(self::INDEX, 'ledger_event'));
        $this->addSql('ALTER TABLE ledger_event DROP COLUMN subject');
    }
}
