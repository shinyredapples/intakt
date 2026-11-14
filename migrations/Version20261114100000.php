<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add to watcher_started_round whether the withdrawal of its approvals is still owed to Review';
    }

    public function up(Schema $schema): void
    {
        $column = Column::editor()->setUnquotedName('withdrawal_owed')->setTypeName(Types::BOOLEAN)->setDefaultValue(false)->create();

        foreach ($this->platform->getAlterTableSQL(new TableDiff($schema->getTable('watcher_started_round'), addedColumns: [$column])) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('watcher_started_round');

        foreach ($this->platform->getAlterTableSQL(new TableDiff($table, droppedColumns: [$table->getColumn('withdrawal_owed')])) as $sql) {
            $this->addSql($sql);
        }
    }
}
