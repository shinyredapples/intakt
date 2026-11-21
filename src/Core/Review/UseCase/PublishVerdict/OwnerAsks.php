<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\PublishVerdict;

use Intakt\Core\Git\Contract\ProtectedChanges;
use Intakt\Core\Git\Contract\ProtectedHeads;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Watcher\Contract\HandedOverChanges;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\HeadStatus;
use Intakt\Plugin\Forge\Contract\PublishedVerdict;
use JsonException;

// Asked on the forge and in the ledger, because a protected head of a pull request handed to Intakt merges only with the owner's approval of that head: docs/design/05-architecture/08-merge.md
final readonly class OwnerAsks
{
    private const string DESCRIPTION = 'Intakt approves this head; it touches protected paths and waits for the owner.';
    private const string HEADING = '**Waiting for the owner** (head %s)';
    private const string ASK = 'This head touches protected paths, so it merges only once the owner has approved this head.';
    private const string UNREADABLE = 'Intakt could not read which paths this head changes, so it counts as protected.';
    private const string LISTED = 'Protected paths:';
    private const string MORE = '- and %d more';
    // Bounded, because the forge refuses a comment past its size limit and the ledger line keeps every path.
    private const int LISTED_PATHS = 50;

    public function __construct(
        private HandedOverChanges $handed,
        private ProtectedHeads $protected,
        private Ledger $ledger,
    ) {
    }

    // Only an approval, because a head sent back goes to its builder before anyone asks the owner, and only a handed-over head, because the loop asks the owner for its own.
    public function askFor(ReviewRound $round, PublishedVerdict $verdict): ?OwnerAsk
    {
        if (HeadStatus::Success !== $verdict->status || null === $verdict->comment || !$this->handed->isHandedToIntakt($round->project(), $round->change())) {
            return null;
        }

        $changes = $this->protected->of($round->project(), $verdict->head->hash);
        if (!$changes->touchesProtectedPaths()) {
            return null;
        }

        // Pending, never success, so Intakt's status cannot stand in for the owner's approval.
        return new OwnerAsk($changes, new PublishedVerdict($verdict->head, HeadStatus::Pending, self::DESCRIPTION, $verdict->comment."\n\n".$this->askText($verdict->head, $changes), $verdict->token));
    }

    // Read and appended under the change's publication lock, which every publication of the change holds, so two rounds of one head record one ask.
    /**
     * @throws OwnerAskNotRecorded
     */
    public function record(ReviewRound $round, OwnerAsk $ask): void
    {
        $head = $ask->verdict->head->hash;
        if ($this->isAskedAlready($round, $head)) {
            return;
        }

        $result = $this->ledger->append(new LedgerEvent(LedgerEventKind::OwnerAsked, $this->payloadOf($round, $ask)));
        if (!$result->succeeded()) {
            throw OwnerAskNotRecorded::of($head, (string) $result->failure);
        }
    }

    private function askText(CommitId $head, ProtectedChanges $changes): string
    {
        $lines = [sprintf(self::HEADING, $head->hash), '', self::ASK];

        // The reason stays in the ledger, because git's message may name folders of the machine Intakt runs on.
        if (null !== $changes->unreadable) {
            $lines = [...$lines, '', self::UNREADABLE];
        }

        if ([] !== $changes->paths) {
            $listed = array_map($this->lineOf(...), array_slice($changes->paths, 0, self::LISTED_PATHS));
            $more = count($changes->paths) - self::LISTED_PATHS;
            $lines = [...$lines, '', self::LISTED, ...$listed, ...($more > 0 ? [sprintf(self::MORE, $more)] : [])];
        }

        return implode("\n", $lines);
    }

    // Escaped, because git allows a newline, a comment opener or markup in a file name, which would forge a line of the ask or hide the rest of the comment.
    private function lineOf(string $path): string
    {
        $escaped = htmlspecialchars(addcslashes($path, "\0..\37\177\\"), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '- <code>'.str_replace('`', '&#96;', $escaped).'</code>';
    }

    private function isAskedAlready(ReviewRound $round, string $head): bool
    {
        foreach ($this->ledger->readMentioning([$head]) as $recorded) {
            if (LedgerEventKind::OwnerAsked === $recorded->event->kind && $this->names($recorded, $round, $head)) {
                return true;
            }
        }

        return false;
    }

    private function names(RecordedEvent $recorded, ReviewRound $round, string $head): bool
    {
        try {
            $payload = json_decode($recorded->event->payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Skipped, because a ledger row is never rewritten and one bad row must not stop a publication.
            return false;
        }

        return is_array($payload) && [$round->project(), $round->change(), $head] === [$payload['project'] ?? null, $payload['change'] ?? null, $payload['head'] ?? null];
    }

    // Invalid UTF-8 substituted, because git allows any bytes in a file name and the line must still be written.
    private function payloadOf(ReviewRound $round, OwnerAsk $ask): string
    {
        return json_encode([
            'project' => $round->project(),
            'change' => $round->change(),
            'head' => $ask->verdict->head->hash,
            'round' => $round->id()->toRfc4122(),
            'paths' => $ask->changes->paths,
            'unreadable' => $ask->changes->unreadable,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    }
}
