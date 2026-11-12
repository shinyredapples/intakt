<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Shadow\Domain;

use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Shadow\Contract\LoopFinding;
use Intakt\Core\Shadow\Contract\LoopReview;
use Intakt\Core\Shadow\Contract\LoopReviews;
use Intakt\Core\Shadow\Contract\LoopVerdict;

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
