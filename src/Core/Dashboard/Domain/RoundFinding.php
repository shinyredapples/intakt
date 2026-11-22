<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Intakt\Core\Review\Contract\NearLine;
use Intakt\Core\Review\Contract\QuoteNearLines;
use Intakt\Core\Review\Contract\QuotesCounted;

final readonly class RoundFinding
{
    public JudgeCall $call;

    /**
     * @param list<string>         $merged
     * @param list<QuoteNearLines> $nearest
     */
    public function __construct(
        public string $id,
        public ?string $severity,
        public ?string $text,
        public array $merged = [],
        public ?string $file = null,
        public ?int $line = null,
        public ?string $droppedBecause = null,
        public QuotesCounted $quotes = new QuotesCounted(),
        private array $nearest = [],
    ) {
        $this->call = JudgeCall::NotActedOn;
    }

    /**
     * @return list<NearLine>
     */
    public function nearLinesOf(string $quote): array
    {
        // Looked up by the quote the ledger row recorded, because the page never gets the change text and must not search it again.
        return array_find($this->nearest, static fn (QuoteNearLines $near): bool => $near->quote === $quote)->lines ?? [];
    }

    /**
     * @return non-empty-list<string>
     */
    public function ids(): array
    {
        return [$this->id, ...$this->merged];
    }

    // The first sentence of its first line, because a finding has no title of its own and a card shows one short line; null when no text is recorded, white space included.
    public function headline(): ?string
    {
        $line = trim(preg_replace('/\R.*/su', '', trim((string) $this->text)) ?? '');
        if ('' === $line) {
            return null;
        }

        // A sentence ends at a mark followed by a space outside an inline code span, so a dot in a name (src/A.php, 1.5) or a question in `Ready? yes` never ends it.
        return 1 === preg_match('/^(?:`[^`]*`|[^`]|`)*?[.!?](?=\s)/u', $line, $sentence) ? $sentence[0] : $line;
    }

    public function place(): ?string
    {
        if (null === $this->file) {
            return null;
        }

        return null === $this->line ? $this->file : $this->file.':'.$this->line;
    }

    public function callInWords(): string
    {
        return $this->call->inWords();
    }

    public function calledBy(JudgeCall $call): self
    {
        return clone ($this, ['call' => $call]);
    }
}
