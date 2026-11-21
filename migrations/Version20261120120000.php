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

// Both shapes of the table are written out instead of read from the database, so a dry run that also plans the migration creating the table can plan this one.
final class Version20261120120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the work intake sync generation per project, also when the forge sent no validator';
    }

    public function up(Schema $schema): void
    {
        $this->alter($this->withoutGeneration(), $this->withGeneration());
    }

    public function down(Schema $schema): void
    {
        // A row without a validator held only a generation, which the table no longer keeps.
        $this->addSql('DELETE FROM work_source_validator WHERE validator IS NULL');
        $this->alter($this->withGeneration(), $this->withoutGeneration());
    }

    private function withoutGeneration(): Table
    {
        return $this->table(
            Column::editor()->setUnquotedName('validator')->setTypeName(Types::TEXT)->create(),
        );
    }

    private function withGeneration(): Table
    {
        return $this->table(
            Column::editor()->setUnquotedName('validator')->setTypeName(Types::TEXT)->setNotNull(false)->create(),
            Column::editor()->setUnquotedName('generation')->setTypeName(Types::INTEGER)->setDefaultValue(1)->create(),
        );
    }

    private function table(Column ...$columns): Table
    {
        return Table::editor()
            ->setUnquotedName('work_source_validator')
            ->setColumns(
                Column::editor()->setUnquotedName('project')->setTypeName(Types::STRING)->setLength(255)->create(),
                ...$columns,
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setColumnNames(UnqualifiedName::unquoted('project'))->create(),
            )
            ->create();
    }

    private function alter(Table $from, Table $to): void
    {
        foreach ($this->platform->getAlterTableSQL($this->connection->createSchemaManager()->createComparator()->compareTables($from, $to)) as $sql) {
            $this->addSql($sql);
        }
    }
}
