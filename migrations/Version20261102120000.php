<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the budget tables of account cooldowns, shared holds and outage notices';
    }

    public function up(Schema $schema): void
    {
        foreach ([$this->accountCooldown(), $this->sharedHold(), $this->outageNotice()] as $table) {
            foreach ($this->platform->getCreateTableSQL($table) as $sql) {
                $this->addSql($sql);
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['budget_outage_notice', 'budget_shared_hold', 'budget_account_cooldown'] as $table) {
            $this->addSql($this->platform->getDropTableSQL($table));
        }
    }

    private function accountCooldown(): Table
    {
        return Table::editor()
            ->setUnquotedName('budget_account_cooldown')
            ->setColumns(
                $this->position(),
                $this->provider(),
                $this->accountName(),
                Column::editor()->setUnquotedName('until')->setTypeName(Types::DATETIME_IMMUTABLE)->create(),
                Column::editor()->setUnquotedName('placed_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create(),
            )
            ->setPrimaryKeyConstraint($this->positionKey())
            ->setIndexes(
                Index::editor()->setUnquotedName('budget_account_cooldown_account')->setUnquotedColumnNames('provider', 'account')->create(),
            )
            ->create();
    }

    private function sharedHold(): Table
    {
        return Table::editor()
            ->setUnquotedName('budget_shared_hold')
            ->setColumns(
                $this->position(),
                Column::editor()->setUnquotedName('hold')->setTypeName(Types::STRING)->setLength(16)->create(),
                Column::editor()->setUnquotedName('until')->setTypeName(Types::DATETIME_IMMUTABLE)->create(),
                Column::editor()->setUnquotedName('placed_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create(),
            )
            ->setPrimaryKeyConstraint($this->positionKey())
            ->create();
    }

    private function outageNotice(): Table
    {
        return Table::editor()
            ->setUnquotedName('budget_outage_notice')
            ->setColumns(
                $this->position(),
                $this->provider(),
                $this->accountName(),
                Column::editor()->setUnquotedName('last_reading_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create(),
                Column::editor()->setUnquotedName('noticed_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create(),
            )
            ->setPrimaryKeyConstraint($this->positionKey())
            ->setIndexes(
                Index::editor()->setUnquotedName('budget_outage_notice_outage')->setType(IndexType::UNIQUE)->setUnquotedColumnNames('provider', 'account', 'last_reading_at')->create(),
            )
            ->create();
    }

    private function position(): Column
    {
        return Column::editor()->setUnquotedName('position')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create();
    }

    private function provider(): Column
    {
        return Column::editor()->setUnquotedName('provider')->setTypeName(Types::STRING)->setLength(32)->create();
    }

    private function accountName(): Column
    {
        return Column::editor()->setUnquotedName('account')->setTypeName(Types::STRING)->setLength(255)->create();
    }

    private function positionKey(): PrimaryKeyConstraint
    {
        return PrimaryKeyConstraint::editor()->setColumnNames(UnqualifiedName::unquoted('position'))->create();
    }
}
