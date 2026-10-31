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
    private const array COUNTERS = ['restarts', 'attempts'];

    public function getDescription(): string
    {
        return 'Add the restarts of a stopped worker\'s reviewer and the attempts of every reviewer to round_reviewer';
    }

    public function up(Schema $schema): void
    {
        $counters = array_map(static fn (string $name): Column => Column::editor()->setUnquotedName($name)->setTypeName(Types::INTEGER)->setDefaultValue(0)->create(), self::COUNTERS);

        foreach ($this->platform->getAlterTableSQL(new TableDiff($schema->getTable('round_reviewer'), addedColumns: $counters)) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('round_reviewer');
        $counters = array_map($table->getColumn(...), self::COUNTERS);

        foreach ($this->platform->getAlterTableSQL(new TableDiff($table, droppedColumns: $counters)) as $sql) {
            $this->addSql($sql);
        }
    }
}
