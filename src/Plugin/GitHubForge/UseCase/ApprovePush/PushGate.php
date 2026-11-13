<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\UseCase\ApprovePush;

use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\GitHubForgeServices;
use Intakt\Plugin\GitHubForge\Domain\ChangeNotShown;
use Intakt\Plugin\GitHubForge\Domain\CiScan;
use Intakt\Plugin\GitHubForge\Domain\CiScanResult;
use Intakt\Plugin\GitHubForge\Domain\CommitNotRead;
use Intakt\Plugin\GitHubForge\Domain\HeldChange;
use Intakt\Plugin\GitHubForge\Domain\IssueComment;
use Intakt\Plugin\GitHubForge\Domain\PushApproval;
use Intakt\Plugin\GitHubForge\Domain\PushRequest;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueComments;
use Intakt\Plugin\GitHubForge\Infrastructure\MirrorChange;
use Intakt\Plugin\GitHubForge\Infrastructure\MirrorWorkflows;
use Intakt\Plugin\GitHubForge\Infrastructure\SecretScan;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

// A risky CI change stays local until the owner's account approves exactly its head, because the forge runs a pushed workflow before anyone looked.
final readonly class PushGate
{
    public function __construct(
        private MirrorChange $changes,
        private MirrorWorkflows $workflows,
        private CiScan $scan,
        private GitHubIssueComments $comments,
        private PushApproval $approval,
        private PushRequest $request,
        private SecretScan $secrets,
        private LockFactory $locks,
        #[Autowire(env: 'GITHUB_OWNER_LOGIN')]
        private string $owner,
    ) {
    }

    /**
     * @throws CommitNotRead
     * @throws RepositoryAttributesRefused
     * @throws ChangeNotShown
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     * @throws ForgeRateLimited
     */
    public function decide(string $repository, string $head, string $project, int $issue): PushOutcome
    {
        // The head is resolved once and the scan reads that commit, so a branch moved meanwhile cannot slip another commit past the approval.
        $change = $this->changes->of($repository, $head);
        $scan = $this->scan->of($this->workflows->of($repository, $change->commit));
        if (!$scan->holdsThePush()) {
            return new PushOutcome(PushDecision::Free, $change->commit, $scan);
        }

        return new PushOutcome($this->whileAskingAlone($change, $scan, $project, $issue), $change->commit, $scan);
    }

    /**
     * @throws ChangeNotShown
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     * @throws ForgeRateLimited
     */
    private function whileAskingAlone(HeldChange $change, CiScanResult $scan, string $project, int $issue): PushDecision
    {
        // Not waited for, because the busy run's caller tries again and then finds the request the other one posted; the commit id and the issue number hold no dot, so the key is unambiguous.
        $lock = $this->locks->createLock(GitHubForgeServices::PUSH_REQUEST_LOCK_PREFIX.$issue.'.'.$change->commit);
        if (!$lock->acquire()) {
            throw new ForgeWriteFailed('Another run is asking the owner about this head.');
        }

        try {
            return $this->askTheOwner($change, $scan, $project, $issue);
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws ChangeNotShown
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     * @throws ForgeRateLimited
     */
    private function askTheOwner(HeldChange $change, CiScanResult $scan, string $project, int $issue): PushDecision
    {
        $comments = $this->comments->of($project, $issue);
        if ($this->approval->isGivenIn($comments, $this->owner, $change->commit)) {
            return PushDecision::Approved;
        }

        if ($this->wasAsked($comments, $change->commit)) {
            return PushDecision::StillAsked;
        }

        $this->comments->post($project, $issue, $this->shownRequest($change, $scan));

        return PushDecision::Asked;
    }

    /**
     * @param list<IssueComment> $comments
     */
    private function wasAsked(array $comments, string $commit): bool
    {
        $marker = $this->request->marker($commit);

        return array_any($comments, static fn (IssueComment $comment): bool => $comment->byThisApp && str_contains($comment->body, $marker));
    }

    /**
     * @throws ChangeNotShown
     * @throws ForgeWriteFailed
     */
    private function shownRequest(HeldChange $change, CiScanResult $scan): string
    {
        $body = $this->request->for($change, $scan);

        // The diff is the builder's text, and an issue comment is public, so a credential in it is never posted.
        return $this->secrets->findsSecretIn($body) ? throw ChangeNotShown::because('the diff holds text the secret scan finds, so it is not posted') : $body;
    }
}
