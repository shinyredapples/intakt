<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// What the session log of one build said it spent, or why it says nothing; it feeds the allowance only, never a start decision or a ceiling, because the builder could rewrite its log (docs/design/05-architecture/06-budget-and-model-planner.md).
#[Exclude]
final readonly class SessionUsage
{
    private const string READING = 'reading';

    private function __construct(
        public UsageReading $reading,
        public ?BuildUsage $usage,
    ) {
    }

    public static function read(BuildUsage $usage): self
    {
        return new self(UsageReading::Read, $usage);
    }

    public static function withoutUsage(UsageReading $reading): self
    {
        if (UsageReading::Read === $reading) {
            throw new InvalidArgumentException('A usage that was read carries its counts.');
        }

        return new self($reading, null);
    }

    // The reader's one line, checked like any untrusted text, because a reader that broke or was made to answer something else must leave the usage unknown, never settle it.
    public static function fromAnswer(string $answer): self
    {
        $decoded = json_decode(trim($answer), true, 2);
        $reading = is_array($decoded) && is_string($decoded[self::READING] ?? null) ? UsageReading::tryFrom($decoded[self::READING]) : null;

        if (!$reading instanceof UsageReading || !$reading->canBeAnsweredByTheReader() || !is_array($decoded)) {
            return new self(UsageReading::ReaderFailed, null);
        }

        if (UsageReading::Read !== $reading) {
            return new self($reading, null);
        }

        $usage = BuildUsage::fromCounts($decoded);

        return new self($usage instanceof BuildUsage ? UsageReading::Read : UsageReading::ReaderFailed, $usage);
    }

    public function answer(): string
    {
        return json_encode($this->payload(), JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, string|int|null>
     */
    public function payload(): array
    {
        return [self::READING => $this->reading->value, ...($this->usage?->counts() ?? array_fill_keys(BuildUsage::COUNTS, null))];
    }
}
