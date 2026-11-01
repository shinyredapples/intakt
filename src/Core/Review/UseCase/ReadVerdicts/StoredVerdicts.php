<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadVerdicts;

use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundReviewer;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class StoredVerdicts
{
    public function __construct(private SerializerInterface $serializer)
    {
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
                $verdicts[] = new PanelVerdict($reviewer->tier(), $verdict);
            }
        }

        return $verdicts;
    }

    public function ofReviewer(RoundReviewer $reviewer): ?Verdict
    {
        $json = $reviewer->verdictJson();

        return null === $json ? null : $this->serializer->deserialize($json, Verdict::class, 'json');
    }
}
