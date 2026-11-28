<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadVerdicts;

use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\ReviewLane;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Infrastructure\ReviewerAnswer\Verdict as ReviewerAnswer;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class StoredVerdicts
{
    public function __construct(
        #[Target('stored')]
        private SerializerInterface $serializer,
    ) {
    }

    /**
     * @return list<PanelVerdict>
     */
    public function of(ReviewRound $round): array
    {
        $verdicts = [];

        foreach ($round->reviewers() as $reviewer) {
            $verdict = $this->ofReviewer($reviewer);

            if ($verdict instanceof Verdict) {
                $verdicts[] = new PanelVerdict($reviewer->tier(), $verdict, ReviewLane::ofRole($reviewer->roleName()));
            }
        }

        return $verdicts;
    }

    public function ofReviewer(RoundReviewer $reviewer): ?Verdict
    {
        $json = $reviewer->verdictJson();

        // Read as the reviewer's answer format, because a stored round holds the JSON that format defines.
        return null === $json ? null : $this->serializer->deserialize($json, ReviewerAnswer::class, JsonEncoder::FORMAT)->toVerdict();
    }
}
