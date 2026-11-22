<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Dashboard\Domain\FailingHead;
use Intakt\Core\Dashboard\Domain\HeadFault;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayload;
use Intakt\Core\Ledger\Contract\RecordedEvents;

// Payloads are decoded to arrays here, because the Watcher module writes its lines without payload classes, so the card needs nothing from it.
final readonly class LedgerFailingHeads
{
    private const array FAILED = [LedgerEventKind::HeadFailed, LedgerEventKind::HeadHandedOver];

    // A later line of the watcher on the same pull request means its head was read or started since, or its pull request closed, so the failure no longer holds it.
    private const array SETTLED = [LedgerEventKind::HeadPending, LedgerEventKind::HeadRed, LedgerEventKind::RoundQueued, LedgerEventKind::HeadForgotten];

    // A round or a closed pull request ends the item, while a pending or red read is recorded before the stale check, which may fail again and keeps the failure count.
    private const array ENDED = [LedgerEventKind::RoundQueued, LedgerEventKind::HeadForgotten];

    public function __construct(private Ledger $ledger)
    {
    }

    /**
     * @return list<FailingHead>
     */
    public function now(): array
    {
        return $this->in($this->ledger->readInOrder());
    }

    /**
     * @return list<FailingHead>
     */
    public function in(RecordedEvents $events): array
    {
        /** @var array<string, array<string, FailingHead>> $failing */
        $failing = [];
        /** @var array<string, array<string, ?string>> $handedOver */
        $handedOver = [];

        foreach ($events as $recorded) {
            $kind = $recorded->event->kind;

            if (!in_array($kind, [...self::FAILED, ...self::SETTLED], true)) {
                continue;
            }

            $payload = LedgerPayload::of($recorded);
            $project = $payload['project'] ?? null;
            $change = $payload['change'] ?? null;

            if (!is_string($project) || !is_string($change)) {
                continue;
            }

            // Keyed by project, then pull request, so no name either side sends can make two keys meet.
            unset($failing[$project][$change]);
            $handedOver[$project][$change] = $this->handedOverHead($kind, $payload['head'] ?? null, $handedOver[$project][$change] ?? null);
            $failing[$project] = ($failing[$project] ?? []) + $this->readableFailure($kind, $project, $payload);
        }

        return $this->markHandedOver($failing, $handedOver);
    }

    // Kept through later lines of the same commit until the item ends, because a moved head is a new item that has not been handed over.
    private function handedOverHead(LedgerEventKind $kind, mixed $head, ?string $handedOver): ?string
    {
        return match (true) {
            !is_string($head) => null,
            LedgerEventKind::HeadHandedOver === $kind => $head,
            in_array($kind, self::ENDED, true) => null,
            $head === $handedOver => $head,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, FailingHead>
     */
    private function readableFailure(LedgerEventKind $kind, string $project, array $payload): array
    {
        $change = $payload['change'] ?? null;
        $cause = $payload['cause'] ?? null;
        $failures = $payload['failures'] ?? null;
        $retryAt = $this->momentOf($payload['retry_at'] ?? null);

        if (!in_array($kind, self::FAILED, true) || !is_string($change) || !is_string($cause) || !is_int($failures) || !$retryAt instanceof DateTimeImmutable) {
            return [];
        }

        return [$change => new FailingHead($project, $change, new HeadFault($cause, $failures, $retryAt))];
    }

    private function momentOf(mixed $moment): ?DateTimeImmutable
    {
        $parsed = is_string($moment) ? DateTimeImmutable::createFromFormat(DATE_ATOM, $moment, new DateTimeZone('UTC')) : false;

        return false === $parsed ? null : $parsed;
    }

    /**
     * @param array<string, array<string, FailingHead>> $failing
     * @param array<string, array<string, ?string>>     $handedOver
     *
     * @return list<FailingHead>
     */
    private function markHandedOver(array $failing, array $handedOver): array
    {
        $heads = [];

        foreach ($failing as $project => $changes) {
            foreach ($changes as $change => $head) {
                $heads[] = null !== ($handedOver[$project][$change] ?? null) ? $head->handedOver() : $head;
            }
        }

        return $heads;
    }
}
