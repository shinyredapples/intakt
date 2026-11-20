<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Plugin\Forge\Contract\ChangeAuthorship;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Plugin\Forge\Contract\Opening;
use JsonException;
use Psr\Log\LoggerInterface;

// Decided from Intakt's own push records, and from the author the forge sets only where a record does not say, because a pull request's title, labels and branch are text others can shape: docs/architecture/handed-to-intakt.md
final readonly class ChangesHandedToIntakt
{
    public function __construct(
        private Ledger $ledger,
        private WatcherLedger $records,
        private ChangeAuthorship $authorship,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<OpenChange> $open
     *
     * @throws WatcherEventNotRecorded
     */
    public function recognizeAmong(string $project, array $open): void
    {
        if ([] === $open) {
            return;
        }

        // Every author is read before the first line is appended, because a failure behind an append rolls the tick's transaction back under a line the entity manager still holds, and the ledger's next append then collides with it.
        foreach ($this->createdByIntaktAmong($project, $open) as $change) {
            $this->records->changeHandedToIntakt($project, $change);
        }
    }

    /**
     * @param list<OpenChange> $open
     *
     * @return list<OpenChange>
     */
    private function createdByIntaktAmong(string $project, array $open): array
    {
        $openings = $this->openingsOf($project);
        $intended = $this->intendedHeadsOf($project);
        $created = [];
        $undecided = [];

        foreach ($this->unhandedAmong($project, $open) as $address => $change) {
            match ($this->decidedBy($openings[$address] ?? null, $intended, $change)) {
                true => $created[] = $change,
                null => $undecided[] = $change,
                false => null,
            };
        }

        return [...$created, ...$this->createdAmongAsked($project, $undecided)];
    }

    /**
     * @param list<OpenChange> $open
     *
     * @return array<string, OpenChange>
     */
    private function unhandedAmong(string $project, array $open): array
    {
        $handed = $this->handedOf($project);
        $unhanded = [];

        foreach ($open as $change) {
            $address = $this->addressOf($change->id->value);

            if (!isset($handed[$address])) {
                $unhanded[$address] ??= $change;
            }
        }

        return $unhanded;
    }

    // Only an opening that created the pull request, because Intakt's push also updates an open pull request someone else opened on the same branch; null leaves it to the forge's author, because a record written before openings were recorded does not say, and a push that stopped between the forge's creation and its record left only the intent for that head.
    /**
     * @param list<mixed>|null    $openings
     * @param array<string, true> $intended
     */
    private function decidedBy(?array $openings, array $intended, OpenChange $change): ?bool
    {
        return match (true) {
            null === $openings => isset($intended[$change->head->hash]) ? null : false,
            in_array(Opening::Created->value, $openings, true) => true,
            in_array(Opening::Updated->value, $openings, true) => false,
            default => null,
        };
    }

    // An unanswered author leaves only that pull request for the next tick, which asks again, because one unreadable pull request must not hold back the listing and the queueing of the others; after a rate limit no further author is asked on this tick, as the forge would refuse each.
    /**
     * @param list<OpenChange> $undecided
     *
     * @return list<OpenChange>
     */
    private function createdAmongAsked(string $project, array $undecided): array
    {
        $created = [];

        foreach ($undecided as $change) {
            try {
                if ($this->authorship->isCreatedByIntakt($change->id)) {
                    $created[] = $change;
                }
            } catch (ForgeReadFailed $unread) {
                $this->logger->warning('The author of {change} in {project} could not be read, so it is checked again on the next tick: {reason}', ['change' => $change->id->value, 'project' => $project, 'reason' => $unread->getMessage()]);
            } catch (ForgeRateLimited $limited) {
                $this->logger->warning('The author of {change} in {project} was not read under the rate limit, so it is checked again on the next tick: {reason}', ['change' => $change->id->value, 'project' => $project, 'reason' => $limited->getMessage()]);

                break;
            }
        }

        return $created;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function openingsOf(string $project): array
    {
        $openings = [];

        foreach ($this->payloadsOf($project, LedgerEventKind::ChangeOpened) as $payload) {
            $change = $payload['change'] ?? null;

            if (is_string($change)) {
                $openings[$this->addressOf($change)][] = $payload['opening'] ?? null;
            }
        }

        return $openings;
    }

    /**
     * @return array<string, true>
     */
    private function intendedHeadsOf(string $project): array
    {
        $heads = [];

        foreach ($this->payloadsOf($project, LedgerEventKind::ChangeIntended) as $payload) {
            $head = $payload['head'] ?? null;

            if (is_string($head)) {
                $heads[$head] = true;
            }
        }

        return $heads;
    }

    /**
     * @return array<string, true>
     */
    private function handedOf(string $project): array
    {
        $handed = [];

        foreach ($this->payloadsOf($project, LedgerEventKind::ChangeHandedToIntakt) as $payload) {
            $change = $payload['change'] ?? null;

            if (is_string($change)) {
                $handed[$this->addressOf($change)] = true;
            }
        }

        return $handed;
    }

    /**
     * @return list<array<mixed>>
     */
    private function payloadsOf(string $project, LedgerEventKind $kind): array
    {
        $payloads = [];

        foreach ($this->ledger->readOfKind($kind) as $recorded) {
            $payload = $this->decoded($recorded->event->payload);

            if ($project === ($payload['project'] ?? null)) {
                $payloads[] = $payload;
            }
        }

        return $payloads;
    }

    // Without case, because the push names the repository as configured and the listing as the forge spells it, and forge addresses do not tell owners or repositories apart by case.
    private function addressOf(string $change): string
    {
        return strtolower($change);
    }

    /**
     * @return array<mixed>
     */
    private function decoded(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Skipped, because a ledger row is never rewritten and one bad row must not stop a tick.
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
