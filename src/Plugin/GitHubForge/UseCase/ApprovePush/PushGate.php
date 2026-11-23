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
use Intakt\Plugin\GitHubForge\Domain\TaskIssue;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueComments;
use Intakt\Plugin\GitHubForge\Infrastructure\MirrorChange;
use Intakt\Plugin\GitHubForge\Infrastructure\MirrorWorkflows;
use Intakt\Plugin\GitHubForge\Infrastructure\SecretScan;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Lazy;
use Symfony\Component\Lock\LockFactory;

// A risky CI change stays local until the owner's account approves exactly its head, because the forge runs a pushed workflow before anyone looked.
final readonly class PushGate
{
    public function __construct(
        private MirrorChange $changes,
        private MirrorWorkflows $workflows,
        private CiScan $scan,
        // Built when first used, so a head that needs no approval is decided on an install without GitHub settings.
        #[Lazy]
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
    public function decide(string $repository, string $head, TaskIssue $issue): PushOutcome
    {
        // The head is resolved once and the scan reads that commit, so a branch moved meanwhile cannot slip another commit past the approval.
        $change = $this->changes->of($repository, $head);
        $scan = $this->scan->of($this->workflows->of($repository, $change->commit));
        if (!$scan->holdsThePush()) {
            return new PushOutcome(PushDecision::Free, $change->commit, $scan);
        }

        return new PushOutcome($this->whileAskingAlone($change, $scan, $issue), $change->commit, $scan);
    }

    /**
     * @throws ChangeNotShown
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     * @throws ForgeRateLimited
     */
    private function whileAskingAlone(HeldChange $change, CiScanResult $scan, TaskIssue $issue): PushDecision
    {
        // Not waited for, because the busy run's caller tries again and then finds the request the other one posted.
        $lock = $this->locks->createLock($this->lockKey($issue, $change->commit));
        if (!$lock->acquire()) {
            throw new ForgeWriteFailed('Another run is asking the owner about this head.');
        }

        try {
            return $this->askTheOwner($change, $scan, $issue);
        } finally {
            $lock->release();
        }
    }

    // The project name is length-prefixed, because a project name may hold the dots that separate the other parts.
    private function lockKey(TaskIssue $issue, string $commit): string
    {
        return sprintf('%s%d:%s.%d.%s', GitHubForgeServices::PUSH_REQUEST_LOCK_PREFIX, strlen($issue->project), $issue->project, $issue->number, $commit);
    }

    /**
     * @throws ChangeNotShown
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     * @throws ForgeRateLimited
     */
    private function askTheOwner(HeldChange $change, CiScanResult $scan, TaskIssue $issue): PushDecision
    {
        // Built before any approval is read, so a change the issue cannot show in full stays held even when an approve for it stands.
        $request = $this->shownRequest($change, $scan);
        $comments = $this->comments->of($issue);
        $asked = $this->requestIndex($comments, $request);
        if (null === $asked) {
            $this->comments->post($issue, $request);

            return PushDecision::Asked;
        }

        // Only an approve after the request counts, because the owner approves the diff the issue shows, not a head they never saw.
        if ($this->approval->isGivenIn(array_slice($comments, $asked + 1), $this->owner, $change->commit)) {
            return PushDecision::Approved;
        }

        return PushDecision::StillAsked;
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

    /**
     * @param list<IssueComment> $comments
     */
    // Only the request exactly as built now, never edited, counts, because the owner approves the diff the issue shows, and an admin can replace or edit a comment of the App.
    private function requestIndex(array $comments, string $request): ?int
    {
        return array_find_key($comments, static fn (IssueComment $comment): bool => $comment->byThisApp && !$comment->edited && $request === $comment->body);
    }
}
