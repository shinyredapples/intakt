<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Built only from the verdict plain code read, never from the reviewer's text, so what the live view shows of an answer is never the model's raw output.
final readonly class ReadAnswer
{
    /**
     * @param list<ReadFinding> $findings
     */
    private function __construct(
        public ?Decision $verdict,
        public array $findings,
    ) {
    }

    public static function of(Verdict $verdict): self
    {
        return new self($verdict->verdict, array_map(ReadFinding::of(...), $verdict->findings));
    }

    // Without a verdict or a finding, because an answer plain code could not read has nothing in it that is safe to show.
    public static function unreadable(): self
    {
        return new self(null, []);
    }

    public function wasRead(): bool
    {
        return $this->verdict instanceof Decision;
    }
}
