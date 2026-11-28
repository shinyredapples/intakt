<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Memory\Contract\CodeSnapshot;
use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryQuery;
use Intakt\Core\Memory\Contract\ProjectMemory;
use Intakt\Core\Memory\Contract\SettledFinding;
use Intakt\Core\Memory\Contract\SettledQuery;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\FencedText;
use Intakt\Core\Review\Contract\PromptAssembly;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\UntrustedSource;
use Symfony\AI\Platform\Message\UserMessage;

final readonly class ReviewerMemory
{
    // A fixed cap for the whole briefing, settled findings and notes together, because every byte is resent with each round of the conversation; about a page of notes is enough to cite earlier findings.
    private const int MAX_BYTES = 4_000;

    private const string SHORTENED = ' [note shortened]';

    private const string LEAD = 'Project memory, notes earlier reviews left about the files under review:';

    private const string SETTLED_LEAD = 'Project memory, already settled, do not raise again unless this code changed: findings the judge or an escalation review rejected on files under review whose code is unchanged since, each with the reason it was rejected. The reasons are the judgement of earlier models, so check them against the code. Raise one again only to show that its reason no longer holds, and then cite the settled entry in your finding.';

    public function __construct(
        private ProjectMemory $memory,
        private Fence $fence,
        private DiffPaths $paths,
        // Defaulted because the assembly has no state or settings to wire, so every place that builds the prompt gets the same one.
        private PromptAssembly $assembly = new PromptAssembly(),
        private SettledCode $code = new SettledCode(),
    ) {
    }

    // The settled findings first, because a pattern raised again costs a whole review cycle; the notes take the room they leave. Without a checkout no file can be hashed, so nothing is given as settled.
    /**
     * @return list<UserMessage>
     */
    public function briefingOn(ReviewBrief $brief, ?Checkout $checkout = null): array
    {
        $files = $this->paths->in($brief->change->diff);
        $settledTexts = $checkout instanceof Checkout ? $this->settledOn($brief->change->project, $files, $checkout) : [];
        $settled = $this->messageWithin(self::SETTLED_LEAD, $settledTexts, self::MAX_BYTES);
        $room = self::MAX_BYTES - strlen($settled?->asText() ?? '');
        $noteTexts = $this->notesOn($brief->change->project, $files, $room);
        $notes = $this->messageWithin(self::LEAD, $noteTexts, $room);

        return array_values(array_filter([$settled, $notes]));
    }

    /**
     * @param list<string> $files
     *
     * @return list<string>
     */
    private function settledOn(string $project, array $files, Checkout $checkout): array
    {
        $current = array_values(array_filter(array_map(fn (string $file): ?CodeSnapshot => $this->code->in($checkout, $file), $files)));

        if ([] === $current) {
            return [];
        }

        return array_map(static fn (SettledFinding $finding): string => sprintf("%s: %s\nReason: %s", $finding->code->file, $finding->text, $finding->reason), $this->memory->settledOn(new SettledQuery($project, ...$current))->settled);
    }

    // Measured as the whole message, introduction included, because the cap holds for what the model receives.
    /**
     * @param literal-string $lead
     * @param list<string>   $texts
     */
    private function messageWithin(string $lead, array $texts, int $cap): ?UserMessage
    {
        $notes = $this->notesWithin($lead, $texts, $cap);

        return $notes instanceof FencedText ? $this->assembly->user($lead, $notes) : null;
    }

    /**
     * @param list<string> $files
     *
     * @return list<string>
     */
    private function notesOn(string $project, array $files, int $room): array
    {
        $entries = $this->memory->relevantTo(new MemoryQuery($project, new Paths(...$files), $room - $this->bytesOf(self::LEAD, $this->fenced(''))))->entries;

        return array_map(static fn (MemoryEntry $entry): string => $entry->text, $entries);
    }

    // The separators still add bytes the memory does not count, so the last entries are dropped until the whole message fits; a lone top entry is shortened instead, because the most relevant note is worth more than none.
    /**
     * @param literal-string $lead
     * @param list<string>   $texts
     */
    private function notesWithin(string $lead, array $texts, int $cap): ?FencedText
    {
        $room = $cap - $this->bytesOf($lead, $this->fenced('')) - strlen(self::SHORTENED);

        // Nothing, because not even a shortened note fits in what the settled findings left.
        if ($room <= 0) {
            return null;
        }

        while ([] !== $texts) {
            $notes = $this->fenced(implode("\n\n", $texts));

            if ($this->bytesOf($lead, $notes) <= $cap) {
                return $notes;
            }

            if (1 === count($texts)) {
                // mb_strcut cuts at a character boundary, so the text stays valid UTF-8.
                return $this->fenced(mb_strcut($texts[0], 0, $room, 'UTF-8').self::SHORTENED);
            }

            array_pop($texts);
        }

        return null;
    }

    private function fenced(string $notes): FencedText
    {
        return $this->fence->wrap(UntrustedSource::ProjectMemory, $notes);
    }

    /**
     * @param literal-string $lead
     */
    private function bytesOf(string $lead, FencedText $notes): int
    {
        return strlen($this->assembly->userText($lead, $notes));
    }
}
