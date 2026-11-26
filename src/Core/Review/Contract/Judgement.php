<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints\Valid;

// Property names are the JSON field names because Symfony AI's structured output ignores serializer name mapping; excluded because that output builds it, not the container.
#[Exclude]
final readonly class Judgement
{
    // Private and set only by the fallback, so the answer schema (public properties) and the deserializer leave it out and a model can never claim it.
    private bool $fallback;

    // Private for the same reason, so a model's answer is always recorded as a model call.
    private VerdictPath $path;

    /**
     * @param list<JudgedFinding> $marks
     */
    public function __construct(
        #[Valid]
        public array $marks,
    ) {
        $this->fallback = false;
        $this->path = VerdictPath::ModelCall;
    }

    // Every blocking finding is act and none is linked to another, because the reviewers agree on each (ReviewersAgree) and a P1 or P2 reaches the act list whatever a model would mark it.
    /**
     * @param list<Finding> $findings
     */
    public static function inPlainCode(array $findings): self
    {
        return clone (self::everyBlockingFindingActs($findings), ['fallback' => false, 'path' => VerdictPath::PlainCode]);
    }

    // Every blocking finding is act, because a judge that cannot be read must never drop one; a P3 gets no mark and so never reaches the builder.
    /**
     * @param list<Finding> $findings
     */
    public static function everyBlockingFindingActs(array $findings): self
    {
        $blocking = array_filter($findings, static fn (Finding $finding): bool => Severity::P3 !== $finding->severity);

        return clone (new self(array_values(array_map(static fn (Finding $finding): JudgedFinding => new JudgedFinding($finding->id, Mark::Act), $blocking))), ['fallback' => true]);
    }

    // True for the fallback for an answer that cannot be read, whatever it marks, because a fallback over P1 and P2 findings alone marks every one of them.
    public function fallback(): bool
    {
        return $this->fallback;
    }

    public function path(): VerdictPath
    {
        return $this->path;
    }

    // Keeps whether this is the fallback and its path, because a check that rewrites the marks must not turn the fallback into the judge's answer.
    /**
     * @param list<JudgedFinding> $marks
     */
    public function withMarks(array $marks): self
    {
        return clone ($this, ['marks' => $marks]);
    }
}
