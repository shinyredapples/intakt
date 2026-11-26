<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ReplayDemo;

use Intakt\Core\Dashboard\UseCase\CreateDemoProject\CreateDemoProjectCommand;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordedLedgerEvent;
use Intakt\Core\Dashboard\UseCase\RecordDemo\Recording;
use Intakt\Core\Ledger\Contract\LedgerEventKind;

// A call's settled usage names only the call, so a replayed call ID the ledger already holds would make another project's usage count as the demo's and go with it on removal.
final readonly class HeldCalls
{
    /**
     * @param list<string> $payloads
     */
    public function __construct(private array $payloads)
    {
    }

    /**
     * @throws ReplayIsRefused
     */
    public function refuseThoseOf(Recording $recording): void
    {
        $call = $this->firstHeldCallOf($recording);

        if (null !== $call) {
            throw $this->anEarlierPlayHolds($call, $recording) ? ReplayIsRefused::becauseTheLedgerHoldsCall($call) : ReplayIsRefused::becauseAnotherReviewHoldsCall($call);
        }
    }

    private function firstHeldCallOf(Recording $recording): ?string
    {
        $held = array_map(self::callOf(...), $this->payloads);
        $calls = array_map(static fn (RecordedLedgerEvent $event): ?string => LedgerEventKind::UsageRecorded === $event->kind ? self::callOf($event->payload) : null, $recording->events);

        return array_find($calls, static fn (?string $call): bool => null !== $call && in_array($call, $held, true));
    }

    private static function callOf(string $payload): ?string
    {
        return self::stringOf($payload, 'call');
    }

    private static function stringOf(string $payload, string $key): ?string
    {
        $row = json_decode($payload, true);
        $value = is_array($row) ? ($row[$key] ?? null) : null;

        return is_string($value) ? $value : null;
    }

    // Only the demo project's row of the call in a round of this recording is an earlier Play of it, because another recording replayed before may reuse the call ID under its own rounds.
    private function anEarlierPlayHolds(string $call, Recording $recording): bool
    {
        $rounds = array_map(static fn (RecordedLedgerEvent $event): ?string => $call === self::callOf($event->payload) ? self::stringOf($event->payload, 'round') : null, $recording->events);

        return array_any($this->payloads, static fn (string $payload): bool => $call === self::callOf($payload)
            && CreateDemoProjectCommand::NAME === self::stringOf($payload, 'project')
            && null !== self::stringOf($payload, 'round')
            && in_array(self::stringOf($payload, 'round'), $rounds, true));
    }
}
