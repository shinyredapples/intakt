<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;

// The latest watcher line of a pull request decides, as in LedgerFailingHeads, so a head read again after a failure waits again and a queued round or a closed pull request ends the wait.
final readonly class LedgerWaitingHeads
{
    private const array WATCHER_LINES = [
        LedgerEventKind::HeadPending,
        LedgerEventKind::HeadRed,
        LedgerEventKind::HeadFailed,
        LedgerEventKind::HeadHandedOver,
        LedgerEventKind::RoundQueued,
        LedgerEventKind::HeadForgotten,
    ];

    public function __construct(private Ledger $ledger)
    {
    }

    /**
     * @return list<array{project: string, change: string, decision: 'pending'|'red', read_again_at: ?string}>
     */
    public function now(): array
    {
        $latest = [];

        foreach ($this->ledger->readInOrder() as $recorded) {
            $kind = $recorded->event->kind;

            if (!in_array($kind, self::WATCHER_LINES, true)) {
                continue;
            }

            $payload = LedgerPayload::of($recorded);
            $project = $payload['project'] ?? null;
            $change = $payload['change'] ?? null;

            if (!is_string($project) || !is_string($change)) {
                continue;
            }

            // Keyed by project, then pull request, so no name either side sends can make two keys meet.
            $latest[$project][$change] = $this->waitOf($kind, $project, $change, $payload['read_again_at'] ?? null);
        }

        return array_values(array_filter(array_merge(...array_map(array_values(...), array_values($latest)))));
    }

    /**
     * @return array{project: string, change: string, decision: 'pending'|'red', read_again_at: ?string}|null
     */
    private function waitOf(LedgerEventKind $kind, string $project, string $change, mixed $readAgainAt): ?array
    {
        $decision = match ($kind) {
            LedgerEventKind::HeadPending => 'pending',
            LedgerEventKind::HeadRed => 'red',
            default => null,
        };

        return null === $decision ? null : ['project' => $project, 'change' => $change, 'decision' => $decision, 'read_again_at' => $this->momentOf($readAgainAt)];
    }

    // Read and written again, so a malformed moment in a row reads as none instead of reaching the status as it was stored.
    private function momentOf(mixed $moment): ?string
    {
        $parsed = is_string($moment) ? DateTimeImmutable::createFromFormat(DATE_ATOM, $moment, new DateTimeZone('UTC')) : false;

        return false === $parsed ? null : $parsed->format(DATE_ATOM);
    }
}
