<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the substitute provider that reviewed in a reviewer\'s place to round_reviewer';
    }

    public function up(Schema $schema): void
    {
        $column = Column::editor()->setUnquotedName('substitute')->setTypeName(Types::STRING)->setLength(32)->setNotNull(false)->create();

        foreach ($this->platform->getAlterTableSQL(new TableDiff($schema->getTable('round_reviewer'), addedColumns: [$column])) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $column = $schema->getTable('round_reviewer')->getColumn('substitute');

        foreach ($this->platform->getAlterTableSQL(new TableDiff($schema->getTable('round_reviewer'), droppedColumns: [$column])) as $sql) {
            $this->addSql($sql);
        }
    }
}
