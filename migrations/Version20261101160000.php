<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the budget table of the measured use per account and window';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->platform->getCreateTableSQL($this->windowReading()) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('budget_window_reading'));
    }

    private function windowReading(): Table
    {
        return Table::editor()
            ->setUnquotedName('budget_window_reading')
            ->setColumns(
                Column::editor()->setUnquotedName('position')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create(),
                Column::editor()->setUnquotedName('provider')->setTypeName(Types::STRING)->setLength(32)->create(),
                Column::editor()->setUnquotedName('account')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('usage_window')->setTypeName(Types::STRING)->setLength(16)->create(),
                Column::editor()->setUnquotedName('used_percent')->setTypeName(Types::FLOAT)->create(),
                Column::editor()->setUnquotedName('resets_at')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotNull(false)->create(),
                Column::editor()->setUnquotedName('taken_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setColumnNames(UnqualifiedName::unquoted('position'))->create(),
            )
            ->setIndexes(
                Index::editor()->setUnquotedName('budget_window_reading_window')->setUnquotedColumnNames('provider', 'account', 'usage_window')->create(),
            )
            ->create();
    }
}
