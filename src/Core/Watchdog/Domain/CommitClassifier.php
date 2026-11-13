<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Domain;

use Intakt\Core\Watchdog\Contract\Violation;
use Intakt\Core\Watchdog\Contract\ViolationKind;

final readonly class CommitClassifier
{
    /**
     * @param list<SeenCommit> $commits
     * @param list<string>     $broughtByRecordedMerges
     *
     * @return list<Violation>
     */
    public function violationsIn(array $commits, array $broughtByRecordedMerges): array
    {
        $violations = [];
        foreach ($commits as $commit) {
            $kind = $this->kindOf($commit, $broughtByRecordedMerges);

            if ($kind instanceof ViolationKind) {
                $violations[] = new Violation($kind, $commit->name);
            }
        }

        return $violations;
    }

    /**
     * @param list<string> $broughtByRecordedMerges
     */
    private function kindOf(SeenCommit $commit, array $broughtByRecordedMerges): ?ViolationKind
    {
        // Because a recorded merge's provenance is the pull request Intakt recorded, whatever the signatures of the commits it brought say.
        if (in_array($commit->name, $broughtByRecordedMerges, true)) {
            return null;
        }

        return match ($commit->signature) {
            SignatureStatus::ByOwnerKey => null,
            SignatureStatus::NoSignature => ViolationKind::Unsigned,
            SignatureStatus::NotByOwnerKey => ViolationKind::UnknownKey,
        };
    }
}
