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

final class Version20261113100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the watcher table of main\'s boot result per project, main commit and tool fingerprint';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->platform->getCreateTableSQL($this->bootResult()) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('watcher_boot_result'));
    }

    private function bootResult(): Table
    {
        return Table::editor()
            ->setUnquotedName('watcher_boot_result')
            ->setColumns(
                Column::editor()->setUnquotedName('project')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('main_commit')->setTypeName(Types::STRING)->setLength(64)->create(),
                Column::editor()->setUnquotedName('tool_fingerprint')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('state')->setTypeName(Types::STRING)->setLength(16)->create(),
                Column::editor()->setUnquotedName('reason')->setTypeName(Types::TEXT)->create(),
                Column::editor()->setUnquotedName('recorded_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setColumnNames(
                    UnqualifiedName::unquoted('project'),
                    UnqualifiedName::unquoted('main_commit'),
                    UnqualifiedName::unquoted('tool_fingerprint'),
                )->create(),
            )
            ->create();
    }
}
