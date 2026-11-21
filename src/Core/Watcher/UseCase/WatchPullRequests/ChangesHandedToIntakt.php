<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\PublishHandedOverChange;
use Intakt\Plugin\Forge\Contract\ChangeAuthorship;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Plugin\Forge\Contract\Opening;
use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

// Decided from Intakt's own push records, and from the author the forge sets only where a record does not say, because a pull request's title, labels and branch are text others can shape: docs/architecture/handed-to-intakt.md
final readonly class ChangesHandedToIntakt
{
    public function __construct(
        private Ledger $ledger,
        private WatcherLedger $records,
        private ChangeAuthorship $authorship,
        private LoggerInterface $logger,
        private MessageBusInterface $commands,
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
        [$created, $notCreated] = $this->authorsAmong($project, $open);
        $unqueued = $this->unqueuedAmong($project, $open);

        foreach ($created as $change) {
            $this->records->changeHandedToIntakt($project, $change);
            $this->queuePublicationOf($project, $change);
        }

        // Also a pull request handed over before publications were queued, because a round of its head judged then published nothing, and its unchanged head starts no judging that would.
        foreach ($unqueued as $change) {
            $this->queuePublicationOf($project, $change);
        }

        // Recorded, because the author the forge sets never changes, so the forge is asked once per pull request and not on every tick.
        foreach ($notCreated as $change) {
            $this->records->changeNotHandedToIntakt($project, $change);
        }
    }

    // Published once it is handed over, because a round judged before this tick found it the loop's and published nothing; queued in the tick's transaction, so a worker looks for the round only after the line it reads has committed, and recorded, so a later tick queues it no second time.
    private function queuePublicationOf(string $project, OpenChange $change): void
    {
        $this->records->handOverPublicationQueued($project, $change);
        $this->commands->dispatch(new PublishHandedOverChange($project, $change->id->value, $change->head->hash));
    }

    /**
     * @param list<OpenChange> $open
     *
     * @return list<OpenChange>
     */
    private function unqueuedAmong(string $project, array $open): array
    {
        $handed = $this->addressesOf($project, LedgerEventKind::ChangeHandedToIntakt);
        $queued = $this->addressesOf($project, LedgerEventKind::HandOverPublicationQueued);
        $unqueued = [];

        foreach ($open as $change) {
            $address = $this->addressOf($change->id->value);

            if (isset($handed[$address]) && !isset($queued[$address])) {
                $unqueued[$address] ??= $change;
            }
        }

        return array_values($unqueued);
    }

    /**
     * @param list<OpenChange> $open
     *
     * @return array{list<OpenChange>, list<OpenChange>}
     */
    private function authorsAmong(string $project, array $open): array
    {
        $openings = $this->openingsOf($project);
        $notHanded = $this->addressesOf($project, LedgerEventKind::ChangeNotHandedToIntakt);
        $created = [];
        $undecided = [];

        foreach ($this->unhandedAmong($project, $open) as $change) {
            match ($this->decidedBy($change, $openings, $notHanded)) {
                true => $created[] = $change,
                null => $undecided[] = $change,
                false => null,
            };
        }

        [$createdAsked, $notCreated] = $this->answersAmong($project, $undecided);

        return [[...$created, ...$createdAsked], $notCreated];
    }

    /**
     * @param list<OpenChange> $open
     *
     * @return array<string, OpenChange>
     */
    private function unhandedAmong(string $project, array $open): array
    {
        $handed = $this->addressesOf($project, LedgerEventKind::ChangeHandedToIntakt);
        $unhanded = [];

        foreach ($open as $change) {
            $address = $this->addressOf($change->id->value);

            if (!isset($handed[$address])) {
                $unhanded[$address] ??= $change;
            }
        }

        return $unhanded;
    }

    // Only an opening that created the pull request, because Intakt's push also updates an open pull request someone else opened on the same branch, and it decides before a recorded author answer, because Intakt's own creation is the stronger fact; null leaves it to the forge's author, because a record written before openings were recorded does not say, and a push that stopped between the forge's creation and its record left none, while the branch may have moved since.
    /**
     * @param array<string, list<mixed>> $openings
     * @param array<string, true>        $notHanded
     */
    private function decidedBy(OpenChange $change, array $openings, array $notHanded): ?bool
    {
        $address = $this->addressOf($change->id->value);
        $opened = $openings[$address] ?? [];

        return match (true) {
            in_array(Opening::Created->value, $opened, true) => true,
            isset($notHanded[$address]), in_array(Opening::Updated->value, $opened, true) => false,
            default => null,
        };
    }

    // An unanswered author leaves only that pull request for the next tick, which asks again, because one unreadable pull request must not hold back the listing and the queueing of the others; after a rate limit no further author is asked on this tick, as the forge would refuse each.
    /**
     * @param list<OpenChange> $undecided
     *
     * @return array{list<OpenChange>, list<OpenChange>}
     */
    private function answersAmong(string $project, array $undecided): array
    {
        $created = [];
        $notCreated = [];

        foreach ($undecided as $change) {
            try {
                if ($this->authorship->isCreatedByIntakt($change->id)) {
                    $created[] = $change;
                } else {
                    $notCreated[] = $change;
                }
            } catch (ForgeReadFailed $unread) {
                $this->logger->warning('The author of {change} in {project} could not be read, so it is checked again on the next tick: {reason}', ['change' => $change->id->value, 'project' => $project, 'reason' => $unread->getMessage()]);
            } catch (ForgeRateLimited $limited) {
                $this->logger->warning('The author of {change} in {project} was not read under the rate limit, so it is checked again on the next tick: {reason}', ['change' => $change->id->value, 'project' => $project, 'reason' => $limited->getMessage()]);

                break;
            }
        }

        return [$created, $notCreated];
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
    private function addressesOf(string $project, LedgerEventKind $kind): array
    {
        $addresses = [];

        foreach ($this->payloadsOf($project, $kind) as $payload) {
            $change = $payload['change'] ?? null;

            if (is_string($change)) {
                $addresses[$this->addressOf($change)] = true;
            }
        }

        return $addresses;
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
