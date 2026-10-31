<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the restarts of a stopped worker\'s reviewer to round_reviewer';
    }

    public function up(Schema $schema): void
    {
        $restarts = Column::editor()->setUnquotedName('restarts')->setTypeName(Types::INTEGER)->setDefaultValue(0)->create();

        foreach ($this->platform->getAlterTableSQL(new TableDiff($schema->getTable('round_reviewer'), addedColumns: [$restarts])) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $restarts = $schema->getTable('round_reviewer')->getColumn('restarts');

        foreach ($this->platform->getAlterTableSQL(new TableDiff($schema->getTable('round_reviewer'), droppedColumns: [$restarts])) as $sql) {
            $this->addSql($sql);
        }
    }
}
