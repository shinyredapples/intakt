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

final class Version20261102100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the watcher tables of the round started per head and the head waiting for its CI';
    }

    public function up(Schema $schema): void
    {
        foreach ([$this->startedRound(), $this->pendingHead()] as $table) {
            foreach ($this->platform->getCreateTableSQL($table) as $sql) {
                $this->addSql($sql);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('watcher_pending_head'));
        $this->addSql($this->platform->getDropTableSQL('watcher_started_round'));
    }

    private function startedRound(): Table
    {
        return Table::editor()
            ->setUnquotedName('watcher_started_round')
            ->setColumns(
                Column::editor()->setUnquotedName('project')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('change_ref')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('head')->setTypeName(Types::STRING)->setLength(64)->create(),
                Column::editor()->setUnquotedName('round_id')->setTypeName(Types::STRING)->setLength(36)->create(),
                Column::editor()->setUnquotedName('stale')->setTypeName(Types::BOOLEAN)->setDefaultValue(false)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setColumnNames(UnqualifiedName::unquoted('change_ref'), UnqualifiedName::unquoted('head'), UnqualifiedName::unquoted('project'))->create(),
            )
            ->create();
    }

    private function pendingHead(): Table
    {
        return Table::editor()
            ->setUnquotedName('watcher_pending_head')
            ->setColumns(
                Column::editor()->setUnquotedName('project')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('change_ref')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('head')->setTypeName(Types::STRING)->setLength(64)->create(),
                Column::editor()->setUnquotedName('updated_at')->setTypeName(Types::STRING)->setLength(26)->create(),
                Column::editor()->setUnquotedName('read_again_at')->setTypeName(Types::STRING)->setLength(26)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setColumnNames(UnqualifiedName::unquoted('change_ref'), UnqualifiedName::unquoted('project'))->create(),
            )
            ->create();
    }
}
