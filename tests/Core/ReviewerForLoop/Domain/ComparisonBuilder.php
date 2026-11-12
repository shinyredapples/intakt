<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\ReviewerForLoop\Domain;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\ReviewerForLoop\Contract\LoopFinding;
use Intakt\Core\ReviewerForLoop\Contract\LoopReview;
use Intakt\Core\ReviewerForLoop\Contract\LoopReviews;
use Intakt\Core\ReviewerForLoop\Contract\LoopVerdict;

final class ComparisonBuilder
{
    public static function loop(LoopFinding ...$findings): LoopReviews
    {
        return new LoopReviews([new LoopReview('correctness', LoopVerdict::Changes, array_values($findings))]);
    }

    public static function loopFinding(string $file, int $line, Severity $severity = Severity::P2): LoopFinding
    {
        return new LoopFinding($file, $line, $severity);
    }

    public static function finding(?string $file, ?int $line, Severity $severity = Severity::P2): Finding
    {
        return new Finding('F1', $severity, $file, $line, null, 'text', FixableBy::Builder, false, null, null);
    }

    public static function round(Verdict $verdict, int $tokens = 100): ChangeReview
    {
        return new ChangeReview('r1', 1, $verdict->verdict, [new PanelVerdict(Tier::Strong, $verdict)], [], new RoundTokens(1, $tokens, 0, 0, 0, 0, $tokens), Cost::unknown());
    }

    public static function changes(Finding ...$findings): Verdict
    {
        return new Verdict(Decision::Changes, array_values($findings), [], []);
    }
}
