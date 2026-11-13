<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Domain;

use Intakt\Core\Watchdog\Contract\Violation;
use Intakt\Core\Watchdog\Contract\ViolationKind;

final readonly class CommitClassifier
{
    /**
     * @param list<SeenCommit> $commits
     * @param list<string>     $recordedMerges
     *
     * @return list<Violation>
     */
    public function violationsIn(array $commits, array $recordedMerges): array
    {
        $violations = [];
        foreach ($commits as $commit) {
            $kind = $this->kindOf($commit, $recordedMerges);

            if ($kind instanceof ViolationKind) {
                $violations[] = new Violation($kind, $commit->name);
            }
        }

        return $violations;
    }

    /**
     * @param list<string> $recordedMerges
     */
    private function kindOf(SeenCommit $commit, array $recordedMerges): ?ViolationKind
    {
        // Because a recorded merge's provenance is the pull request Intakt recorded, whatever its signature says.
        if (in_array($commit->name, $recordedMerges, true)) {
            return null;
        }

        return match ($commit->signature) {
            SignatureStatus::ByOwnerKey => null,
            SignatureStatus::NoSignature => ViolationKind::Unsigned,
            SignatureStatus::NotByOwnerKey => ViolationKind::UnknownKey,
        };
    }
}
