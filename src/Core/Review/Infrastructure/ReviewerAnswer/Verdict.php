<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure\ReviewerAnswer;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FollowUpNote;
use Intakt\Core\Review\Contract\ResolvedFinding;
use Intakt\Core\Review\Contract\Verdict as ContractVerdict;
use Symfony\Component\Validator\Constraints\Valid;

// The reviewer's answer format and the stored rounds' decoding target, kept apart from the contract Verdict so the contract's types can change without changing the schema the reviewers get. Named Verdict because Symfony AI names the schema after the class, and property names are the JSON field names because its structured output ignores serializer name mapping.
final readonly class Verdict
{
    /**
     * @param list<Finding> $findings
     * @param list<string>  $resolved
     * @param list<string>  $follow_ups
     */
    public function __construct(
        public Decision $verdict,
        #[Valid]
        public array $findings,
        public array $resolved,
        public array $follow_ups,
    ) {
    }

    public function toVerdict(): ContractVerdict
    {
        return new ContractVerdict(
            $this->verdict,
            $this->findings,
            array_map(static fn (string $id): ResolvedFinding => new ResolvedFinding($id), $this->resolved),
            array_map(static fn (string $text): FollowUpNote => new FollowUpNote($text), $this->follow_ups),
        );
    }

    public static function fromVerdict(ContractVerdict $verdict): self
    {
        return new self(
            $verdict->verdict,
            $verdict->findings,
            array_map(static fn (ResolvedFinding $resolved): string => $resolved->id, $verdict->resolved),
            array_map(static fn (FollowUpNote $note): string => $note->text, $verdict->follow_ups),
        );
    }
}
