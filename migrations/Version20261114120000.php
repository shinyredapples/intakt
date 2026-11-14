<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep whether the owner alert of a main that fails to boot is still to be recorded';
    }

    public function up(Schema $schema): void
    {
        $current = $this->current();
        $this->alter($current, $current->edit()->addColumn(
            Column::editor()->setUnquotedName('alert_pending')->setTypeName(Types::BOOLEAN)->setDefaultValue(false)->create(),
        )->create());
    }

    public function down(Schema $schema): void
    {
        $current = $this->current();
        $this->alter($current, $current->edit()->dropColumnByUnquotedName('alert_pending')->create());
    }

    private function current(): Table
    {
        return $this->connection->createSchemaManager()->introspectTableByUnquotedName('watcher_boot_result');
    }

    private function alter(Table $from, Table $to): void
    {
        foreach ($this->platform->getAlterTableSQL($this->connection->createSchemaManager()->createComparator()->compareTables($from, $to)) as $sql) {
            $this->addSql($sql);
        }
    }
}
