<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122100000 extends AbstractMigration
{
    private const array TABLES = ['change_findings', 'review_round'];

    public function getDescription(): string
    {
        return 'Store the change address of review rounds in its lowercase canonical form where no other spelling collides';
    }

    // Only a change stored under one spelling moves, because two spellings of one change each hold a finding ledger and a numbering of their own, which cannot be merged without renumbering rounds already published; such a change keeps its old spellings apart, and its new rounds continue the canonical one.
    public function up(Schema $schema): void
    {
        foreach ($this->loneSpellings() as [$project, $spelling]) {
            foreach (self::TABLES as $table) {
                $this->addSql(sprintf('UPDATE %s SET change_ref = ? WHERE project = ? AND change_ref = ?', $table), [strtolower($spelling), $project, $spelling]);
            }
        }
    }

    // Nothing, because the spelling a change was stored under before is not kept anywhere, and the canonical one names the same pull request.
    public function down(Schema $schema): void
    {
    }

    /**
     * @return list<array{string, string}>
     */
    private function loneSpellings(): array
    {
        $spellings = [];

        foreach (self::TABLES as $table) {
            /** @var list<array{project: string, change_ref: string}> $rows */
            $rows = $this->connection->fetchAllAssociative(sprintf('SELECT DISTINCT project, change_ref FROM %s', $table));

            foreach ($rows as $row) {
                // Nested by project, so no project name can run into an address.
                $spellings[$row['project']][strtolower($row['change_ref'])][$row['change_ref']] = true;
            }
        }

        $lone = [];

        foreach ($spellings as $project => $changes) {
            foreach ($changes as $canonical => $written) {
                $spelling = array_key_first($written);

                if (1 === count($written) && $spelling !== $canonical) {
                    $lone[] = [(string) $project, (string) $spelling];
                }
            }
        }

        return $lone;
    }
}
