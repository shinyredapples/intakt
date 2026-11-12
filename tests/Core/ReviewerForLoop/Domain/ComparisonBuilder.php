<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\ReviewerForLoop\Domain;

use Intakt\Core\Review\Contract\Severity;
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

    public static function finding(string $file, int $line, Severity $severity = Severity::P2): LoopFinding
    {
        return self::loopFinding($file, $line, $severity);
    }

    /**
     * @return list<LoopFinding>
     */
    public static function round(LoopFinding ...$findings): array
    {
        return array_values($findings);
    }
}
