<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Closure;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\PartIssue;
use Symfony\Component\Lock\LockFactory;

// The issue number each create of a part returned, kept in the ledger, because GitHub may store a part whose answer lacks its marker, and no search by the marker would find that part again.
final readonly class CreatedParts
{
    public function __construct(
        private Ledger $ledger,
        private LockFactory $locks,
    ) {
    }

    // Not waited for, because the busy run would only find the part the other one is filing, and its caller retries.
    /**
     * @template T
     *
     * @param Closure(): T $filing
     *
     * @return T
     *
     * @throws ForgeWriteFailed
     */
    public function whileHeld(PartPlace $place, PartIssue $part, Closure $filing): mixed
    {
        $lock = $this->locks->createLock($this->subjectOf($place, $part));

        if (!$lock->acquire()) {
            throw new ForgeWriteFailed('Another run is filing this part.');
        }

        try {
            return $filing();
        } finally {
            $lock->release();
        }
    }

    // Of the repository named, because an issue number belongs to one repository and a project alias may be remapped to another before a retry.
    /**
     * @throws ForgeReadFailed
     */
    public function numberOf(PartPlace $place, PartIssue $part): ?int
    {
        $number = null;

        foreach ($this->ledger->readOfKindAbout(LedgerEventKind::PartIssueCreated, $this->subjectOf($place, $part)) as $kept) {
            $fields = PayloadFields::of($kept);

            if ($fields->lacks('issue')) {
                continue;
            }

            if ($fields->text('repository') !== $place->repository) {
                throw new ForgeReadFailed('The ledger kept this part\'s issue number for another repository than the project\'s now.');
            }

            $number = $fields->number('issue');

            if (null === $number || $number < 1) {
                throw new ForgeReadFailed('The ledger kept a created part without its issue number.');
            }
        }

        return $number;
    }

    // Any line of the part, the intent of a create included, because a create whose number was never kept may still have stored the part.
    public function attempted(PartPlace $place, PartIssue $part): bool
    {
        return [] !== [...$this->ledger->readOfKindAbout(LedgerEventKind::PartIssueCreated, $this->subjectOf($place, $part))];
    }

    // Before the create, so a retry that finds no number and no marker stops instead of filing the part a second time.
    /**
     * @throws ForgeWriteFailed
     */
    public function intend(PartPlace $place, PartIssue $part): void
    {
        $appended = $this->ledger->append(new LedgerEvent(LedgerEventKind::PartIssueCreated, json_encode(['project' => $place->project, 'repository' => $place->repository, 'marker' => $part->marker], JSON_THROW_ON_ERROR), $this->subjectOf($place, $part)));

        if (!$appended->succeeded()) {
            throw new ForgeWriteFailed(sprintf('Nothing was filed, because the ledger did not keep the intent to file the part: %s', (string) $appended->failure));
        }
    }

    /**
     * @throws ForgeWriteFailed
     */
    public function keep(PartPlace $place, PartIssue $part, int $number): void
    {
        $appended = $this->ledger->append(new LedgerEvent(LedgerEventKind::PartIssueCreated, json_encode(['project' => $place->project, 'repository' => $place->repository, 'issue' => $number, 'marker' => $part->marker], JSON_THROW_ON_ERROR), $this->subjectOf($place, $part)));

        if (!$appended->succeeded()) {
            throw new ForgeWriteFailed(sprintf('GitHub filed part #%d, but the ledger did not keep its number: %s', $number, (string) $appended->failure));
        }
    }

    // Length-prefixed, because a project name may end in text that looks like the start of a marker.
    private function subjectOf(PartPlace $place, PartIssue $part): string
    {
        return 'part-issue-'.hash('sha256', strlen($place->project).':'.$place->project.$part->marker);
    }
}
