<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the ledger ids of the findings a round resolved, for its session summary, to review_round';
    }

    public function up(Schema $schema): void
    {
        // Nullable rather than defaulted, because DBAL's MySQL platform leaves a default on a text column out of the column declaration, so a declared default would not reach the MySQL schema; null also marks a round completed before this migration.
        $column = Column::editor()->setUnquotedName('resolved_finding_ids')->setTypeName(Types::TEXT)->setNotNull(false)->create();

        foreach ($this->platform->getAlterTableSQL(new TableDiff($schema->getTable('review_round'), addedColumns: [$column])) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('review_round');

        foreach ($this->platform->getAlterTableSQL(new TableDiff($table, droppedColumns: [$table->getColumn('resolved_finding_ids')])) as $sql) {
            $this->addSql($sql);
        }
    }
}
