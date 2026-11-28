<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;

// The Technical writer only proposes the user guide's structure and the docs gate's limits, and the owner decides, so plain code makes a proposal an owner's P3 whatever the model gave it: it never sends a change back and reaches the owner as a follow-up (docs/design/05-architecture/07-reviews.md#the-technical-writer).
final readonly class OwnerProposals
{
    public function applyToVerdict(Verdict $verdict): Verdict
    {
        return $verdict->withFindings(array_map($this->applyTo(...), $verdict->findings));
    }

    private function applyTo(Finding $finding): Finding
    {
        return $finding->namesProposal() ? $finding->withSeverity(Severity::P3)->withFixableBy(FixableBy::Owner) : $finding;
    }
}
