<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Core\Review\Contract\LatestRoundStatus;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Intakt\Plugin\Forge\Contract\StatusLabels;
use Intakt\Plugin\GitHubForge\Contract\GitHubForgeServices;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubForge\Domain\StatusReconciliation;
use Intakt\Plugin\GitHubForge\Domain\TaskIssue;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

final readonly class GitHubStatusLabels implements StatusLabels
{
    private const string RESTORED = 'A person changed the status label on this change. Intakt sets it from the state of the review and put it back, so it always shows where the review stands.';

    public function __construct(
        private LatestRoundStatus $rounds,
        private GitHubStatusReads $reads,
        private WatchedRepositories $repositories,
        private SteeringIdentities $identities,
        private GitHubIssueComments $comments,
        private GitHubLabelWrites $writes,
        private LockFactory $locks,
        private LoggerInterface $logger,
    ) {
    }

    // Skipped without waiting when another writer holds the lock, because the holder reads the stored round under it and every later watcher pass reconciles again, so a skipped write leaves a wrong label for one pass at most.
    public function reconcile(string $project, ChangeId $change): void
    {
        $address = $this->addressOf($project, $change);
        $lock = $this->lockOf($address);
        if (!$lock->acquire()) {
            $this->logger->info('The status label of {change} of {project} was left to the writer holding its lock.', ['change' => $change->value, 'project' => $project]);

            return;
        }

        try {
            $this->matchTheRound($project, $change, $address);
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws ChangeNotFound
     */
    private function addressOf(string $project, ChangeId $change): PullRequestAddress
    {
        $address = PullRequestAddress::of($change);
        if (!$address->isIn($this->repositories->addressOf($project))) {
            throw ChangeNotFound::named($change);
        }

        return $address;
    }

    // Keyed by the pull request in lower case, because GitHub reads its owner and repository so and two spellings name one pull request.
    private function lockOf(PullRequestAddress $address): LockInterface
    {
        return $this->locks->createLock(GitHubForgeServices::STATUS_LABEL_LOCK_PREFIX.hash('sha256', json_encode([strtolower($address->owner), strtolower($address->repository), $address->number], JSON_THROW_ON_ERROR)));
    }

    // Every read comes before the first write, so a read that fails writes nothing and the next pass reconciles.
    /**
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     */
    private function matchTheRound(string $project, ChangeId $change, PullRequestAddress $address): void
    {
        $reconciliation = StatusReconciliation::toward($this->rounds->of($project, $change->value), $this->reads->labelsOn($address));
        if (!$reconciliation->changesLabels()) {
            return;
        }

        $comment = $reconciliation->owesComment($this->reads->of($address), $this->isOwn(...));
        foreach ($reconciliation->remove as $label) {
            $this->writes->remove($address->issuePath(), $label);
        }
        if ($reconciliation->add instanceof RoundStatusLabel) {
            $this->writes->add($address->issuePath(), $reconciliation->add->value);
        }
        if ($comment) {
            $this->comments->post(new TaskIssue($project, $address->number), self::RESTORED);
        }
    }

    /**
     * @throws ForgeReadFailed
     */
    private function isOwn(string $login): bool
    {
        try {
            return $this->identities->isOwn($login);
        } catch (TrustNotChecked $unchecked) {
            throw new ForgeReadFailed($unchecked->getMessage(), $unchecked->getCode(), previous: $unchecked);
        }
    }
}
