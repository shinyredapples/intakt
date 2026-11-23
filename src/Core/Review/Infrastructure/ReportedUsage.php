<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

// One reader for both job CLIs, because each reports usage in an event of its own and the bridges' extractors read it from there.
final readonly class ReportedUsage
{
    // Every count the bridges' extractors read, because they hand each on unchecked.
    private const array COUNTS = ['input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens', 'cached_input_tokens'];

    // Only a usage with figures counts, because an empty one would settle the job as having spent nothing.
    /**
     * @param array<array<mixed>> $events
     *
     * @return list<TokenUsageInterface>
     *
     * @throws ReviewJobFailure
     */
    public static function in(Provider $provider, TokenUsageExtractorInterface $extractor, array $events): array
    {
        $usage = [];
        foreach ($events as $event) {
            $reported = $event['usage'] ?? null;
            if (is_array($reported) && !self::countsAreWhole($reported)) {
                throw ReviewJobFailure::unreadableUsage($provider);
            }
            $read = is_array($reported) ? $extractor->extract(new InMemoryRawResult($event)) : null;
            if ($read instanceof TokenUsageInterface && (null !== $read->getPromptTokens() || null !== $read->getCompletionTokens())) {
                $usage[] = $read;
            }
        }

        return $usage;
    }

    // A negative count would settle the job below what it spent, and one of another type would stop the extractor with a TypeError.
    /**
     * @param array<mixed> $reported
     */
    private static function countsAreWhole(array $reported): bool
    {
        foreach (self::COUNTS as $count) {
            $value = $reported[$count] ?? null;
            if (null !== $value && (!is_int($value) || $value < 0)) {
                return false;
            }
        }

        return true;
    }
}
