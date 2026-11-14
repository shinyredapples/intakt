<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\PushBranch;

use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\BranchPushRequest;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Plugin\Forge\Contract\ChangeText;
use JsonException;

// One record per phase and per commit the push intends to produce, so a restart reads where the last attempt stopped and never repeats a phase it finished; keyed by project too, because two projects can share history and branch names.
final readonly class PushRecords
{
    private const string TEXT_DIGEST = 'text_digest';

    public function __construct(
        private Ledger $ledger,
    ) {
    }

    public function isIntended(BranchPushRequest $request, string $head): bool
    {
        return null !== $this->latest(LedgerEventKind::PushIntended, $request, $head);
    }

    public function hasPushed(BranchPushRequest $request, string $head): bool
    {
        return null !== $this->latest(LedgerEventKind::BranchPushed, $request, $head);
    }

    // Only for the text the pull request was last given: a rework can correct the text alone and keep the commits, and a text that went back to an earlier wording has to reach the forge again.
    public function openedChange(BranchPushRequest $request, string $head): ?string
    {
        $latest = $this->latest(LedgerEventKind::ChangeOpened, $request, $head);
        $change = $latest['change'] ?? null;

        return is_string($change) && ($latest[self::TEXT_DIGEST] ?? null) === $this->digestOf($request->text) ? $change : null;
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
    public function open(BranchPushRequest $request, string $head, string $change): void
    {
        $this->append(LedgerEventKind::ChangeOpened, [...$this->keyOf($request, $head), 'change' => $change, self::TEXT_DIGEST => $this->digestOf($request->text)]);
    }

    // A digest, because the ledger keeps none of the builder's words; the title's length goes first, so no title and body read as another pair.
    private function digestOf(ChangeText $text): string
    {
        return hash('sha256', strlen($text->title).':'.$text->title.$text->body);
    }

    /**
     * @return array{project: string, branch: string, head: string}
     */
    private function keyOf(BranchPushRequest $request, string $head): array
    {
        return ['project' => $request->project, 'branch' => $request->branch, 'head' => $head];
    }

    /**
     * @return array<mixed>|null
     */
    private function latest(LedgerEventKind $kind, BranchPushRequest $request, string $head): ?array
    {
        $key = $this->keyOf($request, $head);
        $latest = null;

        foreach ($this->ledger->readOfKind($kind) as $event) {
            $payload = $this->decoded($event->event->payload);

            if (($payload['project'] ?? null) === $key['project'] && ($payload['branch'] ?? null) === $key['branch'] && ($payload['head'] ?? null) === $key['head']) {
                $latest = $payload;
            }
        }

        return $latest;
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
