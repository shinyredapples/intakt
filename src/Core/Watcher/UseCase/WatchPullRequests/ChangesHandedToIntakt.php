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

// Decided from Intakt's own push records, and from the author the forge sets only where a record does not say, because a pull request's title, labels and branch are text others can shape: docs/architecture/handed-to-intakt.md
final readonly class ChangesHandedToIntakt
{
    public function __construct(
        private Ledger $ledger,
        private WatcherLedger $records,
        private ChangeAuthorship $authorship,
    ) {
    }

    /**
     * @param list<OpenChange> $open
     *
     * @throws WatcherEventNotRecorded
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function recognizeAmong(string $project, array $open): void
    {
        if ([] === $open) {
            return;
        }

        // Every author is read before the first line is appended, because a forge read that fails behind an append rolls the tick's transaction back under a line the entity manager still holds, and the ledger's next append then collides with it.
        foreach ($this->createdByIntaktAmong($project, $open) as $change) {
            $this->records->changeHandedToIntakt($project, $change);
        }
    }

    /**
     * @param list<OpenChange> $open
     *
     * @return list<OpenChange>
     *
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    private function createdByIntaktAmong(string $project, array $open): array
    {
        $openings = $this->openingsOf($project);
        $handed = $this->handedOf($project);
        $created = [];

        foreach ($open as $change) {
            $address = $this->addressOf($change->id->value);
            $recorded = $openings[$address] ?? null;

            if (null === $recorded || isset($handed[$address])) {
                continue;
            }

            if ($this->isCreatedByIntakt($recorded, $change)) {
                $created[] = $change;
                $handed[$address] = true;
            }
        }

        return $created;
    }

    // Only an opening that created the pull request, because Intakt's push also updates an open pull request someone else opened on the same branch.
    /**
     * @param list<mixed> $openings
     *
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    private function isCreatedByIntakt(array $openings, OpenChange $change): bool
    {
        return match (true) {
            in_array(Opening::Created->value, $openings, true) => true,
            in_array(Opening::Updated->value, $openings, true) => false,
            // Asked of the forge, because a record written before openings were recorded does not say whether the App created the pull request.
            default => $this->authorship->isCreatedByIntakt($change->id),
        };
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function openingsOf(string $project): array
    {
        $openings = [];

        foreach ($this->payloadsOf($project, LedgerEventKind::ChangeOpened) as $address => $payloads) {
            $openings[$address] = array_map(static fn (array $payload): mixed => $payload['opening'] ?? null, $payloads);
        }

        return $openings;
    }

    /**
     * @return array<string, true>
     */
    private function handedOf(string $project): array
    {
        return array_map(static fn (): bool => true, $this->payloadsOf($project, LedgerEventKind::ChangeHandedToIntakt));
    }

    /**
     * @return array<string, list<array<mixed>>>
     */
    private function payloadsOf(string $project, LedgerEventKind $kind): array
    {
        $payloads = [];

        foreach ($this->ledger->readOfKind($kind) as $recorded) {
            $payload = $this->decoded($recorded->event->payload);
            $change = $payload['change'] ?? null;

            if ($project === ($payload['project'] ?? null) && is_string($change)) {
                $payloads[$this->addressOf($change)][] = $payload;
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
