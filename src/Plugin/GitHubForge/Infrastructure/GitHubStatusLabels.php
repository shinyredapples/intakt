<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Intakt\Plugin\Forge\Contract\StatusLabels;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubForge\Domain\StatusReconciliation;
use Intakt\Plugin\GitHubForge\Domain\TaskIssue;

// The lock and the stored round belong to RoundStatusMirror, so this adapter only makes the labels of one pull request match the status it is given.
final readonly class GitHubStatusLabels implements StatusLabels
{
    private const string RESTORED = 'A person changed the status label on this change. Intakt sets it from the state of the review and put it back, so it always shows where the review stands.';

    public function __construct(
        private GitHubStatusReads $reads,
        private WatchedRepositories $repositories,
        private SteeringIdentities $identities,
        private GitHubIssueComments $comments,
        private GitHubLabelWrites $writes,
    ) {
    }

    // Every read comes before the first write, so a read that fails writes nothing and the next pass reconciles.
    public function match(string $project, ChangeId $change, ?RoundStatusLabel $wanted): void
    {
        $address = PullRequestAddress::of($change);
        if (!$address->isIn($this->repositories->addressOf($project))) {
            throw ChangeNotFound::named($change);
        }

        $reconciliation = StatusReconciliation::toward($wanted, $this->reads->labelsOn($address));
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
