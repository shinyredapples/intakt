<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

// An outage that ended before this column existed keeps no end, because the reading that ended it may already be gone; it is only left out of the day's ended notices.
final class Version20261121110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the moment of the reading that ended each outage notice';
    }

    public function up(Schema $schema): void
    {
        $current = $this->current();
        $this->alter($current, $current->edit()->addColumn(
            Column::editor()->setUnquotedName('ended_at')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotNull(false)->create(),
        )->create());
    }

    public function down(Schema $schema): void
    {
        $current = $this->current();
        $this->alter($current, $current->edit()->dropColumnByUnquotedName('ended_at')->create());
    }

    private function current(): Table
    {
        return $this->connection->createSchemaManager()->introspectTableByUnquotedName('budget_outage_notice');
    }

    private function alter(Table $from, Table $to): void
    {
        foreach ($this->platform->getAlterTableSQL($this->connection->createSchemaManager()->createComparator()->compareTables($from, $to)) as $sql) {
            $this->addSql($sql);
        }
    }
}
