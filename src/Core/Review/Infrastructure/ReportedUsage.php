<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

// One reader for both job CLIs, because each reports usage in an event of its own and the bridges' extractors read it from there.
final readonly class ReportedUsage
{
    // Only a usage with figures counts, because an empty one would settle the job as having spent nothing.
    /**
     * @param array<array<mixed>> $events
     *
     * @return list<TokenUsageInterface>
     */
    public static function in(TokenUsageExtractorInterface $extractor, array $events): array
    {
        $usage = [];
        foreach ($events as $event) {
            $read = is_array($event['usage'] ?? null) ? $extractor->extract(new InMemoryRawResult($event)) : null;
            if ($read instanceof TokenUsageInterface && (null !== $read->getPromptTokens() || null !== $read->getCompletionTokens())) {
                $usage[] = $read;
            }
        }

        return $usage;
    }
}
