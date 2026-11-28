<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The tokens a build's session reported, kept apart by kind because Anthropic counts cache reads and writes apart from input_tokens.
#[Exclude]
final readonly class BuildUsage
{
    public const array COUNTS = ['input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_creation_input_tokens'];

    // Far above any session, so that the four counts can be added without leaving PHP's integers.
    public const int MOST = 10 ** 15;

    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheReadTokens,
        public int $cacheCreationTokens,
    ) {
    }

    // Null unless every count is a whole number from zero to MOST, because the log is the builder's to write and a missing, negative or fractional count would settle the build as having spent less than it did.
    /**
     * @param array<mixed> $counts
     */
    public static function fromCounts(array $counts): ?self
    {
        $read = array_map(static fn (string $name): mixed => $counts[$name] ?? null, self::COUNTS);
        $whole = array_filter($read, static fn (mixed $count): bool => is_int($count) && $count >= 0 && $count <= self::MOST);

        if (count($whole) !== count(self::COUNTS)) {
            return null;
        }

        [$input, $output, $cacheRead, $cacheCreation] = array_map(intval(...), $whole);

        return new self($input, $output, $cacheRead, $cacheCreation);
    }

    public function total(): int
    {
        return $this->inputTokens + $this->outputTokens + $this->cacheReadTokens + $this->cacheCreationTokens;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return array_combine(self::COUNTS, [$this->inputTokens, $this->outputTokens, $this->cacheReadTokens, $this->cacheCreationTokens]);
    }
}
