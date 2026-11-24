<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep on watcher_started_round the steering labels its round started with';
    }

    // Added nullable, filled with no labels and only then made required, because a round stored before started with none and a default is not portable for a JSON column.
    public function up(Schema $schema): void
    {
        $table = $schema->getTable('watcher_started_round');
        $nullable = Column::editor()->setUnquotedName('labels')->setTypeName(Types::JSON)->setNotNull(false)->create();

        foreach ($this->platform->getAlterTableSQL(new TableDiff($table, addedColumns: [$nullable])) as $sql) {
            $this->addSql($sql);
        }

        $this->addSql('UPDATE watcher_started_round SET labels = ?', [[]], [Types::JSON]);

        $required = $nullable->edit()->setNotNull(true)->create();
        $withColumn = $table->edit()->addColumn($nullable)->create();
        foreach ($this->platform->getAlterTableSQL(new TableDiff($withColumn, changedColumns: ['labels' => new ColumnDiff($nullable, $required)])) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('watcher_started_round');

        foreach ($this->platform->getAlterTableSQL(new TableDiff($table, droppedColumns: [$table->getColumn('labels')])) as $sql) {
            $this->addSql($sql);
        }
    }
}
