<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ReplayDemo;

use Closure;
use Intakt\Core\Dashboard\UseCase\CreateDemoProject\CreateDemoProjectCommand;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordedLedgerEvent;
use Intakt\Core\Dashboard\UseCase\RecordDemo\Recording;
use Intakt\Core\Ledger\Contract\LedgerEventKind;

// A call's settled usage names only the call, so a replayed call ID the ledger already holds would make another project's usage count as the demo's and go with it on removal.
final readonly class HeldCalls
{
    /**
     * @param list<string> $payloads
     * @param list<?string> $startedRounds
     */
    public function __construct(private array $payloads, private array $startedRounds)
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

    // A Play of a usage-only recording writes no start row, so a held call in a round that has one, in the ledger or in this recording, came from another review.
    private function anEarlierPlayHolds(string $call, Recording $recording): bool
    {
        $rounds = array_diff(
            $this->roundsOf($recording, static fn (RecordedLedgerEvent $event): bool => $call === self::callOf($event->payload)),
            $this->roundsOf($recording, static fn (RecordedLedgerEvent $event): bool => LedgerEventKind::ReviewStarted === $event->kind),
            array_filter($this->startedRounds, is_string(...)),
        );

        return array_any($this->payloads, static fn (string $payload): bool => $call === self::callOf($payload)
            && CreateDemoProjectCommand::NAME === self::stringOf($payload, 'project')
            && in_array(self::stringOf($payload, 'round'), $rounds, true));
    }

    /**
     * @param Closure(RecordedLedgerEvent): bool $matches
     *
     * @return list<string>
     */
    private function roundsOf(Recording $recording, Closure $matches): array
    {
        $rounds = array_map(static fn (RecordedLedgerEvent $event): ?string => $matches($event) ? self::stringOf($event->payload, 'round') : null, $recording->events);

        return array_values(array_filter($rounds, is_string(...)));
    }
}
