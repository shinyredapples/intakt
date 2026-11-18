<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\AuthorTrust;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubForge\Domain\SteeringTrust;
use NoDiscard;
use Psr\Log\LoggerInterface;
use Throwable;

// The steering rule decides, so an author is trusted exactly when its label or comment would steer; asked on each call, because write access can be taken away between two heads.
final readonly class GitHubAuthorTrust implements AuthorTrust
{
    public function __construct(
        private TrustAnswers $github,
        private CheckedInstallationTokens $tokens,
        private SteeringTrustCheck $trust,
        private LoggerInterface $logger,
    ) {
    }

    #[NoDiscard('an author nobody looks at may run code on the host')]
    public function isAuthorTrusted(ChangeId $change): bool
    {
        try {
            return $this->trustOfAuthor(PullRequestAddress::of($change))->steers();
            // Boundary: logs any failure and answers untrusted, because a check that cannot answer must never let a head run.
        } catch (Throwable $unanswered) {
            $this->logger->warning('The pull request author counts as untrusted, because the trust check could not answer.', ['change' => $change->value, 'exception' => $unanswered]);

            return false;
        }
    }

    private function trustOfAuthor(PullRequestAddress $pull): SteeringTrust
    {
        return $this->trust->check($pull->owner.'/'.$pull->repository, $this->authorOf($pull));
    }

    private function authorOf(PullRequestAddress $pull): string
    {
        $user = $this->github->ofInstallation($pull->pullRequestPath(), $this->tokens->current()->value)['user'] ?? null;
        $login = is_array($user) ? ($user['login'] ?? null) : null;

        return is_string($login) ? $login : throw TrustNotChecked::becauseTheAnswerIsInvalid();
    }
}
