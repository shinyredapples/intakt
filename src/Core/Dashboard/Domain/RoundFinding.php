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
        public ?string $droppedBecause = null,
    ) {
        $this->call = JudgeCall::NotActedOn;
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
            array_push($spans, ...$this->plainSpans(substr($text, $at, $start - $at)));
            $spans[] = ['text' => trim($code), 'code' => true];
            $at = $start + strlen($whole);
        }

        array_push($spans, ...$this->plainSpans(substr($text, $at)));

        $joined = [];
        foreach ($spans as $span) {
            if ('' === $span['text']) {
                continue;
            }

            $last = array_key_last($joined);
            if (null !== $last && !$span['code'] && !$joined[$last]['code']) {
                $joined[$last]['text'] .= $span['text'];
                continue;
            }

            $joined[] = $span;
        }

        return $joined;
    }

    /**
     * @return list<array{text: string, code: bool}>
     */
    private function plainSpans(string $text): array
    {
        // A backtick that closes nothing still means code to the reviewer, so it marks the word after it; with no word after it, it is dropped, so no card shows a literal backtick outside a code span.
        preg_match_all('/`+([^\s`]*)/', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $spans = [];
        $at = 0;
        foreach ($matches as [[$whole, $start], [$word]]) {
            $spans[] = ['text' => substr($text, $at, $start - $at), 'code' => false];
            $spans[] = ['text' => $word, 'code' => true];
            $at = $start + strlen($whole);
        }

        $spans[] = ['text' => substr($text, $at), 'code' => false];

        return $spans;
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
