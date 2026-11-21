<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Intakt\Core\Review\Contract\ChangeAddress;

final class Version20261122100000 extends AbstractMigration
{
    private const array TABLES = ['change_findings', 'review_round'];

    public function getDescription(): string
    {
        return 'Store the GitHub pull-request address of review rounds in its canonical form where no other spelling collides';
    }

    // Only a lone spelling moves, so no published round is renumbered (review-flow.md#one-address-per-pull-request).
    public function up(Schema $schema): void
    {
        foreach ($this->loneSpellings() as [$project, $spelling]) {
            foreach (self::TABLES as $table) {
                $this->addSql(sprintf('UPDATE %s SET change_ref = ? WHERE project = ? AND change_ref = ?', $table), [ChangeAddress::canonical($spelling), $project, $spelling]);
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
        $lone = [];

        foreach ($this->spellingsPerChange() as [$project, $spellings]) {
            $spelling = $spellings[0];

            if (1 === count($spellings) && $spelling !== ChangeAddress::canonical($spelling)) {
                $lone[] = [$project, $spelling];
            }
        }

        return $lone;
    }

    /**
     * @return list<array{string, non-empty-list<string>}>
     */
    private function spellingsPerChange(): array
    {
        $changes = [];

        foreach (self::TABLES as $table) {
            /** @var list<array{project: string, change_ref: string}> $rows */
            $rows = $this->connection->fetchAllAssociative(sprintf('SELECT DISTINCT project, change_ref FROM %s', $table));

            foreach ($rows as $row) {
                // Keyed by a JSON list, so no project name can run into an address.
                $key = json_encode([$row['project'], ChangeAddress::canonical($row['change_ref'])], JSON_THROW_ON_ERROR);
                $changes[$key] ??= [$row['project'], []];
                $changes[$key][1][] = $row['change_ref'];
            }
        }

        return array_values(array_map(static fn (array $change): array => [$change[0], array_values(array_unique($change[1]))], $changes));
    }
}
