<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep a sync generation per project beside the listing validator, which may now be empty';
    }

    public function up(Schema $schema): void
    {
        $current = $this->current();
        $this->alter($current, $current->edit()
            ->modifyColumnByUnquotedName('validator', static function (ColumnEditor $column): void {
                $column->setNotNull(false);
            })
            ->addColumn(Column::editor()->setUnquotedName('generation')->setTypeName(Types::INTEGER)->setDefaultValue(0)->create())
            ->create());
    }

    public function down(Schema $schema): void
    {
        // A row without a validator did not exist before this migration, so it goes instead of blocking the column.
        $this->addSql('DELETE FROM work_source_validator WHERE validator IS NULL');
        $current = $this->current();
        $this->alter($current, $current->edit()
            ->dropColumnByUnquotedName('generation')
            ->modifyColumnByUnquotedName('validator', static function (ColumnEditor $column): void {
                $column->setNotNull(true);
            })
            ->create());
    }

    private function current(): Table
    {
        return $this->connection->createSchemaManager()->introspectTableByUnquotedName('work_source_validator');
    }

    private function alter(Table $from, Table $to): void
    {
        foreach ($this->platform->getAlterTableSQL($this->connection->createSchemaManager()->createComparator()->compareTables($from, $to)) as $sql) {
            $this->addSql($sql);
        }
    }
}
