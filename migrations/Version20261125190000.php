<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep on build_parked_task which run parked the task';
    }

    // Nullable for good, because a rollback keeps the database and the release before parks its tasks without the column; a row without a run is shown by no reader and replaced or cleared by any run (docs/design/02-principles.md, add, don't move, on upgrade).
    public function up(Schema $schema): void
    {
        $table = $schema->getTable('build_parked_task');
        $run = Column::editor()->setUnquotedName('run')->setTypeName(Types::STRING)->setLength(36)->setNotNull(false)->create();

        foreach ($this->platform->getAlterTableSQL(new TableDiff($table, addedColumns: [$run])) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('build_parked_task');

        foreach ($this->platform->getAlterTableSQL(new TableDiff($table, droppedColumns: [$table->getColumn('run')])) as $sql) {
            $this->addSql($sql);
        }
    }
}
