<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\PushBranch;

use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use JsonException;

// One record per phase and per commit the push intends to produce, so a restart reads where the last attempt stopped and never repeats a phase it finished; keyed by project too, because two projects can share history and branch names.
final readonly class PushRecords
{
    public function __construct(
        private Ledger $ledger,
    ) {
    }

    public function isIntended(string $project, string $branch, string $head): bool
    {
        return null !== $this->recorded(LedgerEventKind::PushIntended, $project, $branch, $head);
    }

    public function hasPushed(string $project, string $branch, string $head): bool
    {
        return null !== $this->recorded(LedgerEventKind::BranchPushed, $project, $branch, $head);
    }

    public function openedChange(string $project, string $branch, string $head): ?string
    {
        $change = $this->recorded(LedgerEventKind::ChangeOpened, $project, $branch, $head)['change'] ?? null;

        return is_string($change) ? $change : null;
    }

    /**
     * @throws BranchNotPushed
     */
    public function intend(string $project, string $branch, string $head): void
    {
        $this->append(LedgerEventKind::PushIntended, ['project' => $project, 'branch' => $branch, 'head' => $head]);
    }

    /**
     * @throws BranchNotPushed
     */
    public function push(string $project, string $branch, string $head): void
    {
        $this->append(LedgerEventKind::BranchPushed, ['project' => $project, 'branch' => $branch, 'head' => $head]);
    }

    /**
     * @throws BranchNotPushed
     */
    public function open(string $project, string $branch, string $head, string $change): void
    {
        $this->append(LedgerEventKind::ChangeOpened, ['project' => $project, 'branch' => $branch, 'head' => $head, 'change' => $change]);
    }

    /**
     * @return array<mixed>|null
     */
    private function recorded(LedgerEventKind $kind, string $project, string $branch, string $head): ?array
    {
        foreach ($this->ledger->readOfKind($kind) as $event) {
            $payload = $this->decoded($event->event->payload);

            if (($payload['project'] ?? null) === $project && ($payload['branch'] ?? null) === $branch && ($payload['head'] ?? null) === $head) {
                return $payload;
            }
        }

        return null;
    }

    /**
     * @return array<mixed>
     */
    private function decoded(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Skipped, because a ledger row is never rewritten and one bad row must not stop a push.
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, string> $payload
     *
     * @throws BranchNotPushed
     */
    private function append(LedgerEventKind $kind, array $payload): void
    {
        $result = $this->ledger->append(new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR)));

        if (!$result->succeeded()) {
            throw BranchNotPushed::because(sprintf('the ledger did not record "%s": %s', $kind->value, $result->failure));
        }
    }
}
