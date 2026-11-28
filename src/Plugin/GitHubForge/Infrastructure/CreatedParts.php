<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\PartIssue;

// The issue number each create of a part returned, kept in the ledger, because GitHub may store a part whose answer lacks its marker, and no search by the marker would find that part again.
final readonly class CreatedParts
{
    public function __construct(
        private Ledger $ledger,
    ) {
    }

    /**
     * @throws ForgeReadFailed
     */
    public function numberOf(string $project, PartIssue $part): ?int
    {
        $kept = array_last([...$this->ledger->readOfKindAbout(LedgerEventKind::PartIssueCreated, $this->subjectOf($project, $part))]);

        if (null === $kept) {
            return null;
        }

        $number = PayloadFields::of($kept)->number('issue');

        return null !== $number && $number > 0 ? $number : throw new ForgeReadFailed('The ledger kept a created part without its issue number.');
    }

    /**
     * @throws ForgeWriteFailed
     */
    public function keep(string $project, PartIssue $part, int $number): void
    {
        $appended = $this->ledger->append(new LedgerEvent(LedgerEventKind::PartIssueCreated, json_encode(['project' => $project, 'issue' => $number, 'marker' => $part->marker], JSON_THROW_ON_ERROR), $this->subjectOf($project, $part)));

        if (!$appended->succeeded()) {
            throw new ForgeWriteFailed(sprintf('GitHub filed part #%d, but the ledger did not keep its number: %s', $number, (string) $appended->failure));
        }
    }

    // Length-prefixed, because a project name may end in text that looks like the start of a marker.
    private function subjectOf(string $project, PartIssue $part): string
    {
        return 'part-issue-'.hash('sha256', strlen($project).':'.$project.$part->marker);
    }
}
