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

final class Version20261101130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the watcher tables of the listing validator per project and the head and update time seen per pull request';
    }

    public function up(Schema $schema): void
    {
        foreach ([$this->listingValidator(), $this->seenChange()] as $table) {
            foreach ($this->platform->getCreateTableSQL($table) as $sql) {
                $this->addSql($sql);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('watcher_seen_change'));
        $this->addSql($this->platform->getDropTableSQL('watcher_listing_validator'));
    }

    private function listingValidator(): Table
    {
        return Table::editor()
            ->setUnquotedName('watcher_listing_validator')
            ->setColumns(
                Column::editor()->setUnquotedName('project')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('validator')->setTypeName(Types::TEXT)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setColumnNames(UnqualifiedName::unquoted('project'))->create(),
            )
            ->create();
    }

    private function seenChange(): Table
    {
        return Table::editor()
            ->setUnquotedName('watcher_seen_change')
            ->setColumns(
                Column::editor()->setUnquotedName('project')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('change_ref')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('head')->setTypeName(Types::STRING)->setLength(64)->create(),
                Column::editor()->setUnquotedName('updated_at')->setTypeName(Types::STRING)->setLength(26)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setColumnNames(UnqualifiedName::unquoted('project'), UnqualifiedName::unquoted('change_ref'))->create(),
            )
            ->create();
    }
}
