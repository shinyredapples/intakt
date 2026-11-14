<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\PushBranch;

use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\BranchPushRequest;
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

    public function isIntended(BranchPushRequest $request, string $head): bool
    {
        return null !== $this->recorded(LedgerEventKind::PushIntended, $request, $head);
    }

    public function hasPushed(BranchPushRequest $request, string $head): bool
    {
        return null !== $this->recorded(LedgerEventKind::BranchPushed, $request, $head);
    }

    public function openedChange(BranchPushRequest $request, string $head): ?string
    {
        $change = $this->recorded(LedgerEventKind::ChangeOpened, $request, $head)['change'] ?? null;

        return is_string($change) ? $change : null;
    }

    /**
     * @throws BranchNotPushed
     */
    public function intend(BranchPushRequest $request, string $head): void
    {
        $this->append(LedgerEventKind::PushIntended, $this->keyOf($request, $head));
    }

    /**
     * @throws BranchNotPushed
     */
    public function push(BranchPushRequest $request, string $head): void
    {
        $this->append(LedgerEventKind::BranchPushed, $this->keyOf($request, $head));
    }

    /**
     * @throws BranchNotPushed
     */
    public function open(BranchPushRequest $request, string $head, string $change): void
    {
        $this->append(LedgerEventKind::ChangeOpened, [...$this->keyOf($request, $head), 'change' => $change]);
    }

    /**
     * @return array{project: string, branch: string, head: string}
     */
    private function keyOf(BranchPushRequest $request, string $head): array
    {
        return ['project' => $request->project, 'branch' => $request->branch, 'head' => $head];
    }

    /**
     * @return array<mixed>|null
     */
    private function recorded(LedgerEventKind $kind, BranchPushRequest $request, string $head): ?array
    {
        $key = $this->keyOf($request, $head);

        foreach ($this->ledger->readOfKind($kind) as $event) {
            $payload = $this->decoded($event->event->payload);

            if (($payload['project'] ?? null) === $key['project'] && ($payload['branch'] ?? null) === $key['branch'] && ($payload['head'] ?? null) === $key['head']) {
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
