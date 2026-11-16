<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Closure;
use DateTimeImmutable;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// One per reviewer call and never shared, because the readonly services must keep no text of their own and two reviewers at once must not mix; excluded from the container for the same reason, so its text never outlives the call.
#[Exclude]
final class StreamedTextBatch
{
    // A few times a second, because a publish per delta floods the hub while a slower pace makes the text look stuck.
    public const string INTERVAL = '250 milliseconds';

    // A JSON document or a fenced block, because a structured answer opens with one of them and reaches the page as readable lines (LiveProgress::answered()), never as its raw text.
    private const string ANSWER_OPENS = '{`';

    private string $waiting = '';

    private ?DateTimeImmutable $lastPublished = null;

    private bool $prose = false;

    private bool $heldBack = false;

    /**
     * @param Closure(string): void $publish
     */
    public function __construct(
        private readonly Closure $publish,
        private readonly ClockInterface $clock,
    ) {
    }

    public function add(string $text): void
    {
        if ($this->heldBack) {
            return;
        }

        $this->waiting .= $text;
        $this->decideTurn();

        if ($this->prose && $this->due()) {
            $this->flush();
        }
    }

    // Called on a tool call, because the model's next turn may open its answer although this one was prose.
    public function nextTurn(): void
    {
        $this->flush();
        $this->prose = false;
        $this->heldBack = false;
    }

    public function flush(): void
    {
        if ('' === $this->waiting) {
            return;
        }

        $text = $this->waiting;
        $this->waiting = '';
        $this->lastPublished = $this->clock->now();
        ($this->publish)($text);
    }

    // By the turn's first character that is not blank, because the answer opens with it and narration almost never does.
    private function decideTurn(): void
    {
        $first = substr(ltrim($this->waiting), 0, 1);

        if ($this->prose || '' === $first) {
            return;
        }

        $this->heldBack = str_contains(self::ANSWER_OPENS, $first);
        $this->prose = !$this->heldBack;

        if ($this->heldBack) {
            $this->waiting = '';
        }
    }

    private function due(): bool
    {
        return !$this->lastPublished instanceof DateTimeImmutable || $this->clock->now() >= $this->lastPublished->modify('+'.self::INTERVAL);
    }
}
