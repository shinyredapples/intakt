<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints\Valid;

// Property names are the JSON field names because Symfony AI's structured output ignores serializer name mapping; excluded because that output builds it, not the container.
#[Exclude]
final readonly class Judgement
{
    /**
     * @param list<JudgedFinding> $marks
     */
    public function __construct(
        #[Valid]
        public array $marks,
    ) {
    }

    // Every blocking finding is act, because a judge that cannot be read must never drop one; a P3 gets no mark and so never reaches the builder.
    /**
     * @param list<Finding> $findings
     */
    public static function everyBlockingFindingActs(array $findings): self
    {
        $blocking = array_filter($findings, static fn (Finding $finding): bool => Severity::P3 !== $finding->severity);

        return new self(array_values(array_map(static fn (Finding $finding): JudgedFinding => new JudgedFinding($finding->id, Mark::Act), $blocking)));
    }
}
