<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Review\Contract\Reviewer;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Override;
use Symfony\AI\Platform\PlatformInterface;

// Records the conversation of every review it passes on, so a test can tell a retry in a new conversation from one in the same.
final class ConversationsSeen implements Reviewer
{
    /** @var list<string> */
    public private(set) array $ids = [];

    public function __construct(private readonly Reviewer $reviewer)
    {
    }

    #[Override]
    public function review(ReviewRequest $request, PlatformInterface $platform, ResolvedModel $model): Verdict
    {
        $this->ids[] = $request->conversation->id->toRfc4122();

        return $this->reviewer->review($request, $platform, $model);
    }
}
