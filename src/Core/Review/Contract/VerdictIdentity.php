<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\HeadMove;
use Intakt\Plugin\Forge\Contract\HeadMoveKind;

final readonly class VerdictIdentity
{
    // A dot never occurs in a hexadecimal hash, so the parts of a recorded identity cannot run into each other.
    private const string SEPARATOR = '.';
    private const string DOCUMENTATION = 'docs/';
    private const array COMMIT_FIELDS = [IdentityField::Head, IdentityField::Base];

    public function __construct(
        public CommitRange $commits,
        public ReviewTerms $terms,
    ) {
    }

    public static function fromRecorded(string $recorded): self
    {
        $parts = explode(self::SEPARATOR, $recorded);

        if (count(IdentityField::cases()) !== count($parts)) {
            throw new RecordedIdentityMalformed('A recorded verdict identity has five parts separated by dots.');
        }

        [$head, $base, $taskRevision, $checkCommand, $panelPolicy] = $parts;

        return new self(new CommitRange(new CommitId($base), new CommitId($head)), new ReviewTerms($taskRevision, $checkCommand, $panelPolicy));
    }

    public function recorded(): string
    {
        return implode(self::SEPARATOR, array_map($this->valueOf(...), IdentityField::cases()));
    }

    // The move is passed in because only the forge can read it; it must be the move from this identity's head to the current one.
    public function standingAt(self $current, HeadMove $move): VerdictStanding
    {
        $changed = $this->fieldsChangedIn($current);

        if ([] === $changed) {
            return VerdictStanding::current();
        }

        if (!$this->survives($changed, $move)) {
            return VerdictStanding::stale($changed);
        }

        return VerdictStanding::keptExceptFor($changed, $move->paths);
    }

    /**
     * @return list<IdentityField>
     */
    private function fieldsChangedIn(self $current): array
    {
        return array_values(array_filter(IdentityField::cases(), fn (IdentityField $field): bool => $this->valueOf($field) !== $current->valueOf($field)));
    }

    /**
     * @param list<IdentityField> $changed
     */
    private function survives(array $changed, HeadMove $move): bool
    {
        // Only the commits may have moved, because a new task revision, check command or panel policy asks a different question of the same code.
        if (array_any($changed, static fn (IdentityField $field): bool => !in_array($field, self::COMMIT_FIELDS, true))) {
            return false;
        }

        return match ($move->kind) {
            HeadMoveKind::CleanMergeOfBase => true,
            HeadMoveKind::Commits => !in_array(IdentityField::Base, $changed, true) && $this->touchesOnlyDocumentation($move),
            HeadMoveKind::Unmoved, HeadMoveKind::Rewritten => false,
        };
    }

    private function touchesOnlyDocumentation(HeadMove $move): bool
    {
        return array_all([...$move->paths], static fn (string $path): bool => str_starts_with($path, self::DOCUMENTATION));
    }

    private function valueOf(IdentityField $field): string
    {
        return match ($field) {
            IdentityField::Head => $this->commits->head->hash,
            IdentityField::Base => $this->commits->base->hash,
            IdentityField::TaskRevision => $this->terms->taskRevision,
            IdentityField::CheckCommand => $this->terms->checkCommand,
            IdentityField::PanelPolicy => $this->terms->panelPolicy,
        };
    }
}
