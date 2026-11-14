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
        return 'Add to watcher_started_round whether the withdrawal of its approvals was requested and is still owed to Review';
    }

    public function up(Schema $schema): void
    {
        $columns = [
            Column::editor()->setUnquotedName('withdrawal_owed')->setTypeName(Types::BOOLEAN)->setDefaultValue(false)->create(),
            Column::editor()->setUnquotedName('withdrawal_requested')->setTypeName(Types::BOOLEAN)->setDefaultValue(false)->create(),
        ];

        foreach ($this->platform->getAlterTableSQL(new TableDiff($schema->getTable('watcher_started_round'), addedColumns: $columns)) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('watcher_started_round');

        foreach ($this->platform->getAlterTableSQL(new TableDiff($table, droppedColumns: [$table->getColumn('withdrawal_owed'), $table->getColumn('withdrawal_requested')])) as $sql) {
            $this->addSql($sql);
        }
    }
}
