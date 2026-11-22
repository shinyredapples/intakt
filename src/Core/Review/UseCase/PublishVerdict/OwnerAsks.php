<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\PublishVerdict;

use Intakt\Core\Git\Contract\ProtectedChanges;
use Intakt\Core\Git\Contract\ProtectedHeads;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Watcher\Contract\HandedOverChanges;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\HeadStatus;
use Intakt\Plugin\Forge\Contract\PublishedVerdict;
use JsonException;

// Asked on the forge and in the ledger, because a protected head of a pull request handed to Intakt merges only with the owner's approval of that head, and the request lists every protected change of it: docs/design/05-architecture/08-merge.md
final readonly class OwnerAsks
{
    private const string DESCRIPTION = 'Intakt approves this head; it touches protected paths and waits for the owner.';
    private const string HEADING = '**Waiting for the owner** (head %s)';
    private const string CONTINUED = '**Waiting for the owner** (head %s), continued';
    private const string ASK = 'This head touches protected paths, so it merges only once the owner has approved this head.';
    private const string UNREADABLE = 'Intakt could not read which paths this head changes, so it counts as protected.';
    private const string LISTED = 'Protected paths:';
    // Bytes per part of the ask, below GitHub's 65536 characters per comment with room for the marker, because a part past the limit is refused and a byte is never fewer characters.
    private const int PART_BYTES = 60000;
    // Each written as an entity, because GitHub renders markdown inside <code>, and a newline, a comment opener, emphasis or an issue link in a file name would forge a line of the ask or hide the rest of it.
    private const string MARKUP = '/[&<>"\'`\\\\*_#\[\]~|!()]/';

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
        return new OwnerAsk($changes, new PublishedVerdict($verdict->head, HeadStatus::Pending, self::DESCRIPTION, $verdict->comment, $verdict->token, $this->partsOf($verdict->head, $changes)));
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

    /**
     * @return list<string>
     */
    private function partsOf(CommitId $head, ProtectedChanges $changes): array
    {
        $lines = [sprintf(self::HEADING, $head->hash), '', self::ASK];

        // The reason stays in the ledger, because git's message may name folders of the machine Intakt runs on.
        if (null !== $changes->unreadable) {
            $lines = [...$lines, '', self::UNREADABLE];
        }

        if ($changes->paths->isEmpty()) {
            return [implode("\n", $lines)];
        }

        $parts = [];
        $part = implode("\n", [...$lines, '', self::LISTED]);
        $listed = 0;
        foreach (array_map($this->lineOf(...), [...$changes->paths]) as $line) {
            // A part ends only after a path, so no part is posted that lists none.
            if ($listed > 0 && strlen($part) + 1 + strlen($line) > self::PART_BYTES) {
                $parts[] = $part;
                $part = sprintf(self::CONTINUED, $head->hash)."\n";
                $listed = 0;
            }

            $part .= "\n".$line;
            ++$listed;
        }

        return [...$parts, $part];
    }

    // Control bytes and backslashes spelled out first, so a newline in a name stays on its line; invalid UTF-8 replaced, because git allows any bytes in a file name and the forge takes only text.
    private function lineOf(string $path): string
    {
        $spelled = mb_scrub(addcslashes($path, "\0..\37\177\\"), 'UTF-8');

        return '- <code>'.preg_replace_callback(self::MARKUP, static fn (array $match): string => '&#'.ord($match[0]).';', $spelled).'</code>';
    }

    private function isAskedAlready(ReviewRound $round, string $head): bool
    {
        foreach ($this->ledger->readMentioning(new Mentions($head)) as $recorded) {
            if (LedgerEventKind::OwnerAsked === $recorded->event->kind && $this->names($recorded, $round, $head)) {
                return true;
            }
        }

        return false;
    }

    // The change without letter case, as the hand-over is read, because two rounds may name one pull request in two spellings.
    private function names(RecordedEvent $recorded, ReviewRound $round, string $head): bool
    {
        try {
            $payload = json_decode($recorded->event->payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Skipped, because a ledger row is never rewritten and one bad row must not stop a publication.
            return false;
        }

        $change = is_array($payload) ? ($payload['change'] ?? null) : null;

        return is_string($change) && strtolower($change) === strtolower($round->change()) && [$round->project(), $head] === [$payload['project'] ?? null, $payload['head'] ?? null];
    }

    // Invalid UTF-8 substituted, because git allows any bytes in a file name and the line must still be written.
    private function payloadOf(ReviewRound $round, OwnerAsk $ask): string
    {
        return json_encode([
            'project' => $round->project(),
            'change' => $round->change(),
            'head' => $ask->verdict->head->hash,
            'round' => $round->id()->toRfc4122(),
            'paths' => [...$ask->changes->paths],
            'unreadable' => $ask->changes->unreadable,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    }
}
