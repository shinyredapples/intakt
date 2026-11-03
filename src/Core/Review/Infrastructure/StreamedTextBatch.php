<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Review\Contract\RoundUpdates;
use Override;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

// One per reviewer call and never shared, because the readonly runs service must keep no text of its own and two reviewers at once must not mix.
final class StreamedTextBatch implements ResetInterface
{
    // A few times a second, because a publish per delta floods the hub while a slower pace makes the text look stuck.
    public const string INTERVAL = '250 milliseconds';

    private string $waiting = '';

    private ?DateTimeImmutable $lastPublished = null;

    public function __construct(
        private readonly RoundUpdates $updates,
        private readonly ClockInterface $clock,
        private readonly Uuid $round,
        private readonly string $reviewer,
    ) {
    }

    public function add(string $text): void
    {
        $this->waiting .= $text;

        if ($this->due()) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ('' === $this->waiting) {
            return;
        }

        $text = $this->waiting;
        $this->waiting = '';
        $this->lastPublished = $this->clock->now();
        $this->updates->textStreamed($this->round, $this->reviewer, $text);
    }

    #[Override]
    public function reset(): void
    {
        $this->waiting = '';
        $this->lastPublished = null;
    }

    private function due(): bool
    {
        return !$this->lastPublished instanceof DateTimeImmutable || $this->clock->now() >= $this->lastPublished->modify('+'.self::INTERVAL);
    }
}
