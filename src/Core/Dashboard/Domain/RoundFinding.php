<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class RoundFinding
{
    public JudgeCall $call;

    /**
     * @param list<string> $merged
     */
    public function __construct(
        public string $id,
        public ?string $severity,
        public ?string $text,
        public array $merged = [],
        public ?string $file = null,
        public ?int $line = null,
    ) {
        $this->call = JudgeCall::NotActedOn;
    }

    // Joined with "+" as ReviewReport::asLines() joins an act entry's ids for intakt:review, so the page and the console name a merged entry alike.
    public function entryId(): string
    {
        return implode('+', $this->ids());
    }

    /**
     * @return non-empty-list<string>
     */
    public function ids(): array
    {
        return [$this->id, ...$this->merged];
    }

    /**
     * @return list<array{text: string, code: bool}>
     */
    public function textSpans(): array
    {
        $text = $this->text ?? '';
        // A span closes on a run of as many backticks as opened it, as in Markdown, so ``a`b`` holds one backtick of code.
        preg_match_all('/(?<!`)(`+)(?!`)(.+?)(?<!`)\1(?!`)/s', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $spans = [];
        $at = 0;
        foreach ($matches as [[$whole, $start], , [$code]]) {
            $spans[] = ['text' => substr($text, $at, $start - $at), 'code' => false];
            $spans[] = ['text' => trim($code), 'code' => true];
            $at = $start + strlen($whole);
        }

        $spans[] = ['text' => substr($text, $at), 'code' => false];

        return array_values(array_filter($spans, static fn (array $span): bool => '' !== $span['text']));
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
