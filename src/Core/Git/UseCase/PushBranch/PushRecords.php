<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\PushBranch;

use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\BranchPushRequest;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Plugin\Forge\Contract\ChangeText;
use Intakt\Plugin\Forge\Contract\OpenedChange;
use Intakt\Plugin\Forge\Contract\Opening;
use JsonException;

// One record per phase and per commit the push intends to produce, so a restart reads where the last attempt stopped and never repeats a phase it finished; keyed by project too, because two projects can share history and branch names.
final readonly class PushRecords
{
    private const string TEXT_DIGEST = 'text_digest';
    private const array CHANGE_KINDS = [LedgerEventKind::ChangeIntended, LedgerEventKind::ChangeOpened];

    public function __construct(
        private Ledger $ledger,
    ) {
    }

    public function isIntended(BranchPushRequest $request, string $head): bool
    {
        return $this->last($this->ledger->readOfKind(LedgerEventKind::PushIntended), $request, $head) instanceof LedgerEvent;
    }

    public function hasPushed(BranchPushRequest $request, string $head): bool
    {
        return $this->last($this->ledger->readOfKind(LedgerEventKind::BranchPushed), $request, $head) instanceof LedgerEvent;
    }

    // Only when the last word on the pull request is a record of the text at hand: an intent after it is a write whose outcome the ledger never learned, so the forge may hold another text; a rework can also correct the text alone, and a text that went back to an earlier wording has to reach the forge again.
    public function openedChange(BranchPushRequest $request, string $head): ?string
    {
        $last = $this->last($this->changeRecords(), $request, $head);

        if (LedgerEventKind::ChangeOpened !== $last?->kind) {
            return null;
        }

        $record = $this->decoded($last->payload);
        $change = $record['change'] ?? null;

        // Not without its opening, because a record written before openings were recorded must let the forge check the author again: docs/architecture/handed-to-intakt.md
        $opening = $record['opening'] ?? null;
        $known = is_string($opening) && null !== Opening::tryFrom($opening);

        return $known && is_string($change) && ($record[self::TEXT_DIGEST] ?? null) === $this->digestOf($request->text) ? $change : null;
    }

    // Of the branch and not of one head, because a later run can write the pull request for the same head or another one; a count, because the ledger only ever appends, so a write after a push raises it.
    public function changeRecordsOf(BranchPushRequest $request): int
    {
        $count = 0;

        foreach ($this->changeRecords() as $recorded) {
            $payload = $this->decoded($recorded->event->payload);

            if (($payload['project'] ?? null) === $request->project && ($payload['branch'] ?? null) === $request->branch) {
                ++$count;
            }
        }

        return $count;
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
    public function refuse(BranchPushRequest $request, string $head, string $reason): void
    {
        $this->append(LedgerEventKind::PushRefused, [...$this->keyOf($request, $head), 'reason' => $reason]);
    }

    // Without a digest, because the intent only has to come after the last record: it says a write may have happened, not which text it carried.
    /**
     * @throws BranchNotPushed
     */
    public function intendChange(BranchPushRequest $request, string $head): void
    {
        $this->append(LedgerEventKind::ChangeIntended, $this->keyOf($request, $head));
    }

    // With how the forge got there, because only a pull request Intakt's identity created is handed to Intakt: docs/architecture/handed-to-intakt.md
    /**
     * @throws BranchNotPushed
     */
    public function open(BranchPushRequest $request, string $head, OpenedChange $change): void
    {
        $this->append(LedgerEventKind::ChangeOpened, [...$this->keyOf($request, $head), 'change' => $change->id->value, self::TEXT_DIGEST => $this->digestOf($request->text), 'opening' => $change->opening->value]);
    }

    /**
     * @return array{project: string, branch: string, head: string}
     */
    private function keyOf(BranchPushRequest $request, string $head): array
    {
        return ['project' => $request->project, 'branch' => $request->branch, 'head' => $head];
    }

    /**
     * @return iterable<RecordedEvent>
     */
    // Read in ledger order across both kinds, because a read of one kind cannot say whether an intent came after the last record.
    private function changeRecords(): iterable
    {
        foreach ($this->ledger->readInOrder() as $recorded) {
            if (in_array($recorded->event->kind, self::CHANGE_KINDS, true)) {
                yield $recorded;
            }
        }
    }

    /**
     * @param iterable<RecordedEvent> $events
     */
    private function last(iterable $events, BranchPushRequest $request, string $head): ?LedgerEvent
    {
        $key = $this->keyOf($request, $head);
        $last = null;

        foreach ($events as $recorded) {
            $payload = $this->decoded($recorded->event->payload);

            if (($payload['project'] ?? null) === $key['project'] && ($payload['branch'] ?? null) === $key['branch'] && ($payload['head'] ?? null) === $key['head']) {
                $last = $recorded->event;
            }
        }

        return $last;
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

    // A digest, because the ledger keeps none of the builder's words; the title's length goes first, so no title and body read as another pair.
    private function digestOf(ChangeText $text): string
    {
        return hash('sha256', strlen($text->title).':'.$text->title.$text->body);
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
