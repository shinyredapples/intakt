<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;

final readonly class AcceptedVerdicts
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function cases(): iterable
    {
        $design = VerdictDocument::fromTheDesign();

        yield 'the designs example' => [$design->json()];
        foreach (Decision::cases() as $decision) {
            yield 'decision '.$decision->value => [$design->with('verdict', $decision->value)->json()];
        }
        foreach (Severity::cases() as $severity) {
            yield 'severity '.$severity->value => [$design->withFinding('severity', $severity->value)->json()];
        }
        foreach (FixableBy::cases() as $fixableBy) {
            yield 'fixable by '.$fixableBy->value => [$design->withFinding('fixable_by', $fixableBy->value)->json()];
        }
        yield 'a repeated finding' => [$design->withFinding('repeat', true)->json()];
        yield 'a finding with null place, requirement and evidence' => [
            $design->withFinding('file', null)->withFinding('line', null)->withFinding('symbol', null)
                ->withFinding('requirement', null)->withFinding('evidence', null)->json(),
        ];
        yield 'an approval without findings' => ['{"verdict": "approve", "findings": [], "resolved": [], "follow_ups": []}'];
    }
}
