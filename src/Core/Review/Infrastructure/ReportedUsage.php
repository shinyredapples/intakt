<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

// One reader for both job CLIs, because each reports usage in an event of its own and the bridges' extractors read it from there.
final readonly class ReportedUsage
{
    // Every count the bridges' extractors read, because they hand each on unchecked.
    private const array COUNTS = ['input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens', 'cached_input_tokens'];

    // Only a usage with figures counts, and only one whose figures are all whole numbers of zero or more, because an empty or invalid one would settle the job as having spent nothing or less than it did; such an event counts as no usage, so the reservation stays unsettled.
    /**
     * @param array<array<mixed>> $events
     *
     * @return list<TokenUsageInterface>
     */
    public static function in(TokenUsageExtractorInterface $extractor, array $events): array
    {
        $usage = [];
        foreach ($events as $event) {
            $reported = $event['usage'] ?? null;
            $read = is_array($reported) && self::countsAreWhole($reported) ? $extractor->extract(new InMemoryRawResult($event)) : null;
            if ($read instanceof TokenUsageInterface && (null !== $read->getPromptTokens() || null !== $read->getCompletionTokens())) {
                $usage[] = $read;
            }
        }

        return $usage;
    }

    // Because the extractor hands a negative count on as it is, and one of another type would stop it with a TypeError.
    /**
     * @param array<mixed> $reported
     */
    private static function countsAreWhole(array $reported): bool
    {
        return array_all(array_intersect_key($reported, array_flip(self::COUNTS)), static fn (mixed $count): bool => is_int($count) && $count >= 0);
    }
}
