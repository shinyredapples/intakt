<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

use Intakt\Core\HandBack\Contract\MissingEvidenceItems;
use Intakt\Core\HandBack\Contract\PullRequestTextOutcome;
use Intakt\Core\HandBack\Contract\PullRequestTextVerdict;
use NoDiscard;

final readonly class PullRequestTextRule
{
    // Reviewers read the whole body on every round, so a longer one costs each review more; the forge's own limit is far higher.
    public const int MAX_BODY_CHARACTERS = 4000;

    // The cap is measured before anything is parsed, so a body of any length gets a verdict and never an error.
    #[NoDiscard]
    public static function judge(string $task, string $body): PullRequestTextOutcome
    {
        $characters = mb_strlen($body, 'UTF-8');

        if ($characters > self::MAX_BODY_CHARACTERS) {
            return new PullRequestTextOutcome(PullRequestTextVerdict::BodyOverCap, 0, new MissingEvidenceItems(), $characters);
        }

        $items = EvidenceItems::countIn($task);
        $missing = new MissingEvidenceItems(...self::missingReferences($items, MarkdownText::visible($body)));
        $verdict = $missing->isEmpty() ? PullRequestTextVerdict::Passed : PullRequestTextVerdict::MissingEvidenceReference;

        return new PullRequestTextOutcome($verdict, $items, $missing, $characters);
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
}
