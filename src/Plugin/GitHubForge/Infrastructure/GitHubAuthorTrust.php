<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\AuthorTrust;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
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
            return $this->trustOfAuthor(PullRequestAddress::of($change));
            // Boundary: logs any failure and counts the author as untrusted, because a check that cannot answer must never let a head run.
        } catch (Throwable $unanswered) {
            $this->logger->warning('The pull request author counts as untrusted, because the trust check could not answer.', ['change' => $change->value, 'exception' => $unanswered]);

            return false;
        }
    }

    private function trustOfAuthor(PullRequestAddress $pull): bool
    {
        $answer = $this->github->ofInstallation($pull->pullRequestPath(), $this->tokens->current()->value);
        $login = $this->authorOf($answer);
        $fromTheBaseRepository = $this->isFromTheBaseRepository($answer, $pull);

        return $fromTheBaseRepository && $this->trust->check($pull->owner.'/'.$pull->repository, $login)->steers();
    }

    /**
     * @param array<mixed> $answer
     */
    private function authorOf(array $answer): string
    {
        $user = $answer['user'] ?? null;
        $login = is_array($user) ? ($user['login'] ?? null) : null;

        return is_string($login) ? $login : throw TrustNotChecked::becauseTheAnswerIsInvalid();
    }

    // A fork's owner can push new heads under the author's name, so only a head in the base repository counts; a deleted fork has no repository and counts as foreign.
    /**
     * @param array<mixed> $answer
     */
    private function isFromTheBaseRepository(array $answer, PullRequestAddress $pull): bool
    {
        $head = $answer['head'] ?? null;

        if (!is_array($head) || !array_key_exists('repo', $head)) {
            throw TrustNotChecked::becauseTheAnswerIsInvalid();
        }

        if (null === $head['repo']) {
            return false;
        }

        $name = is_array($head['repo']) ? ($head['repo']['full_name'] ?? null) : null;

        return is_string($name) ? 0 === strcasecmp($name, $pull->owner.'/'.$pull->repository) : throw TrustNotChecked::becauseTheAnswerIsInvalid();
    }
}
