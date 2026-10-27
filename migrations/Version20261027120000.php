<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the append-only ledger_event table';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->platform->getCreateTableSQL($this->ledgerEvent()) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('ledger_event'));
    }

    private function ledgerEvent(): Table
    {
        return Table::editor()
            ->setUnquotedName('ledger_event')
            ->setColumns(
                Column::editor()->setUnquotedName('sequence')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create(),
                Column::editor()->setUnquotedName('kind')->setTypeName(Types::STRING)->setLength(32)->create(),
                Column::editor()->setUnquotedName('payload')->setTypeName(Types::TEXT)->create(),
                Column::editor()->setUnquotedName('recorded_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setColumnNames(UnqualifiedName::unquoted('sequence'))->create(),
            )
            ->create();
    }
}
