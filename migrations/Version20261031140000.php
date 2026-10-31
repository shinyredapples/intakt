<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031140000 extends AbstractMigration
{
    private const array COLUMNS = ['change_ref', 'round_number'];

    public function getDescription(): string
    {
        return 'Create the change_findings table of the finding ledger and add the change and round number to review_round';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->platform->getCreateTableSQL($this->changeFindings()) as $sql) {
            $this->addSql($sql);
        }

        $columns = [
            Column::editor()->setUnquotedName('change_ref')->setTypeName(Types::STRING)->setLength(255)->setDefaultValue('')->create(),
            Column::editor()->setUnquotedName('round_number')->setTypeName(Types::INTEGER)->setDefaultValue(1)->create(),
        ];

        foreach ($this->platform->getAlterTableSQL(new TableDiff($schema->getTable('review_round'), addedColumns: $columns)) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('review_round');
        $columns = array_map($table->getColumn(...), self::COLUMNS);

        foreach ($this->platform->getAlterTableSQL(new TableDiff($table, droppedColumns: $columns)) as $sql) {
            $this->addSql($sql);
        }

        $this->addSql($this->platform->getDropTableSQL('change_findings'));
    }

    private function changeFindings(): Table
    {
        return Table::editor()
            ->setUnquotedName('change_findings')
            ->setColumns(
                Column::editor()->setUnquotedName('project')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('change_ref')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('rounds')->setTypeName(Types::INTEGER)->create(),
                Column::editor()->setUnquotedName('findings')->setTypeName(Types::TEXT)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setColumnNames(UnqualifiedName::unquoted('project'), UnqualifiedName::unquoted('change_ref'))->create(),
            )
            ->create();
    }
}
