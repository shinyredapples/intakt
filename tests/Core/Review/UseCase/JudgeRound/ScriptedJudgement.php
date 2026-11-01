<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\JudgeRound;

use Closure;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use LogicException;
use Override;

// Answers every judge call with the given judgement and keeps the request, so a test sees what the judge was asked; what runs meanwhile happens while the call is out, the moment another worker can act.
final class ScriptedJudgement implements Vendor
{
    public ?JudgeRequest $asked = null;

    public int $calls = 0;

    /**
     * @param ?Closure(): void $meanwhile
     */
    public function __construct(
        private readonly Judgement $judgement,
        private readonly ?Closure $meanwhile = null,
    ) {
    }

    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview
    {
        throw new LogicException('This vendor only judges.');
    }

    #[Override]
    public function judge(string $project, JudgeRequest $request): Judgement
    {
        $this->asked = $request;
        ++$this->calls;

        if ($this->meanwhile instanceof Closure) {
            ($this->meanwhile)();
        }

        return $this->judgement;
    }
}
