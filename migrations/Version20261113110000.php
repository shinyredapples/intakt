<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add to review_round whether its verdicts were made stale because its head failed to boot';
    }

    public function up(Schema $schema): void
    {
        $column = Column::editor()->setUnquotedName('verdicts_stale')->setTypeName(Types::BOOLEAN)->setDefaultValue(false)->create();

        foreach ($this->platform->getAlterTableSQL(new TableDiff($schema->getTable('review_round'), addedColumns: [$column])) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('review_round');

        foreach ($this->platform->getAlterTableSQL(new TableDiff($table, droppedColumns: [$table->getColumn('verdicts_stale')])) as $sql) {
            $this->addSql($sql);
        }
    }
}
