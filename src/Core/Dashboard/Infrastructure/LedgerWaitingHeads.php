<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Ledger\Contract\RecordedEvents;

// The latest watcher line of a pull request decides, as in LedgerFailingHeads, so a head read again after a failure waits again and a queued round or a closed pull request ends the wait.
final readonly class LedgerWaitingHeads
{
    /**
     * @return list<array{project: string, change: string, decision: 'pending'|'red', read_again_at: ?string}>
     */
    public function in(RecordedEvents $events): array
    {
        $latest = [];

        foreach ($events as $recorded) {
            $kind = $recorded->event->kind;

            if (!in_array($kind, WatcherLines::ALL, true)) {
                continue;
            }

            $payload = PayloadFields::of($recorded);
            $project = $payload->text('project');
            $change = $payload->text('change');

            if (null === $project || null === $change) {
                continue;
            }

            // Keyed by project, then pull request, so no name either side sends can make two keys meet.
            $latest[$project][$change] = $this->waitOf($kind, $payload->text('read_again_at'));
        }

        return $this->waiting($latest);
    }

    /**
     * @return array{decision: 'pending'|'red', read_again_at: ?string}|null
     */
    private function waitOf(LedgerEventKind $kind, ?string $readAgainAt): ?array
    {
        $decision = match ($kind) {
            LedgerEventKind::HeadPending => 'pending',
            LedgerEventKind::HeadRed => 'red',
            default => null,
        };

        return null === $decision ? null : ['decision' => $decision, 'read_again_at' => $this->momentOf($readAgainAt)];
    }

    // Names are strings again here, because PHP turns a project or pull request name of digits into an integer key.
    /**
     * @param array<array-key, array<array-key, array{decision: 'pending'|'red', read_again_at: ?string}|null>> $latest
     *
     * @return list<array{project: string, change: string, decision: 'pending'|'red', read_again_at: ?string}>
     */
    private function waiting(array $latest): array
    {
        $waiting = [];

        foreach ($latest as $project => $changes) {
            foreach (array_filter($changes) as $change => $wait) {
                $waiting[] = ['project' => strval($project), 'change' => strval($change)] + $wait;
            }
        }

        return $waiting;
    }

    // Parsed before it is passed on, so a malformed moment in a row reads as none instead of reaching the status as it was stored.
    private function momentOf(?string $moment): ?string
    {
        // The parser throws on a NUL byte instead of refusing it, so such a moment is malformed before it is parsed.
        $parsed = null === $moment || str_contains($moment, "\0") ? false : DateTimeImmutable::createFromFormat(DATE_ATOM, $moment, new DateTimeZone('UTC'));

        // The parser rolls an impossible calendar date over into the next month, so only a moment that formats back to the stored text counts.
        return false === $parsed || $parsed->format(DATE_ATOM) !== $moment ? null : $moment;
    }
}
