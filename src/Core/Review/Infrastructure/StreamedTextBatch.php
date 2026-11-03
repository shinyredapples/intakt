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

    private string $waiting = '';

    private ?DateTimeImmutable $lastPublished = null;

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
        ($this->publish)($text);
    }

    private function due(): bool
    {
        return !$this->lastPublished instanceof DateTimeImmutable || $this->clock->now() >= $this->lastPublished->modify('+'.self::INTERVAL);
    }
}
