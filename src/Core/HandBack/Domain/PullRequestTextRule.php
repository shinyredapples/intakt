<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

use Intakt\Core\HandBack\Contract\PullRequestTextOutcome;
use Intakt\Core\HandBack\Contract\PullRequestTextVerdict;
use NoDiscard;

final readonly class PullRequestTextRule
{
    // Reviewers read the whole body on every round, so a longer one costs each review more; the forge's own limit is far higher.
    public const int MAX_BODY_CHARACTERS = 4000;

    #[NoDiscard]
    public static function judge(string $task, string $body): PullRequestTextOutcome
    {
        $items = EvidenceItems::countIn($task);
        $missing = self::missingReferences($items, MarkdownText::visible($body));
        $characters = mb_strlen($body, 'UTF-8');

        return new PullRequestTextOutcome(self::verdictOf($missing, $characters), $items, $missing, $characters);
    }

    /**
     * @return list<positive-int>
     */
    private static function missingReferences(int $items, string $body): array
    {
        $missing = [];

        for ($item = 1; $item <= $items; ++$item) {
            // Word boundaries on both sides, so E10 does not stand in for E1.
            if (1 !== preg_match('/\bE'.$item.'\b/', $body)) {
                $missing[] = $item;
            }
        }

        return $missing;
    }

    /**
     * @param list<positive-int> $missing
     */
    private static function verdictOf(array $missing, int $characters): PullRequestTextVerdict
    {
        return match (true) {
            [] !== $missing => PullRequestTextVerdict::MissingEvidenceReference,
            $characters > self::MAX_BODY_CHARACTERS => PullRequestTextVerdict::BodyOverCap,
            default => PullRequestTextVerdict::Passed,
        };
    }
}
