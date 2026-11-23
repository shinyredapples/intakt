<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Dashboard\Domain\FailingHead;
use Intakt\Core\Dashboard\Domain\HeadFault;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Ledger\Contract\RecordedEvents;

// Payloads are read by field here, because the Watcher module writes its lines without payload classes, so the card needs nothing from it.
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

            $payload = PayloadFields::of($recorded);
            $project = $payload->text('project');
            $change = $payload->text('change');

            if (null === $project || null === $change) {
                continue;
            }

            // Keyed by project, then pull request, so no name either side sends can make two keys meet.
            unset($failing[$project][$change]);
            $handedOver[$project][$change] = $this->handedOverHead($kind, $payload->text('head'), $handedOver[$project][$change] ?? null);
            $failing[$project] = ($failing[$project] ?? []) + $this->readableFailure($kind, $project, $payload);
        }

        return $this->markHandedOver($failing, $handedOver);
    }

    // Kept through later lines of the same commit until the item ends, because a moved head is a new item that has not been handed over.
    private function handedOverHead(LedgerEventKind $kind, ?string $head, ?string $handedOver): ?string
    {
        return match (true) {
            null === $head => null,
            LedgerEventKind::HeadHandedOver === $kind => $head,
            in_array($kind, self::ENDED, true) => null,
            $head === $handedOver => $head,
            default => null,
        };
    }

    /**
     * @return array<string, FailingHead>
     */
    private function readableFailure(LedgerEventKind $kind, string $project, PayloadFields $payload): array
    {
        $change = $payload->text('change');
        $cause = $payload->text('cause');
        $failures = $payload->number('failures');
        $retryAt = $this->momentOf($payload->text('retry_at'));

        if (!in_array($kind, self::FAILED, true) || null === $change || null === $cause || null === $failures || !$retryAt instanceof DateTimeImmutable) {
            return [];
        }

        return [$change => new FailingHead($project, $change, new HeadFault($cause, $failures, $retryAt))];
    }

    private function momentOf(?string $moment): ?DateTimeImmutable
    {
        // The parser throws on a NUL byte instead of refusing it, so such a moment is malformed before it is parsed.
        $parsed = null === $moment || str_contains($moment, "\0") ? false : DateTimeImmutable::createFromFormat(DATE_ATOM, $moment, new DateTimeZone('UTC'));

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
