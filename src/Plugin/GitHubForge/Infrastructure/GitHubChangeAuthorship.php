<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ChangeAuthorship;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;

// The author GitHub reports for the pull request against this App's own bot account, read with the App's own proof, so neither a person nor another App passes as Intakt.
final readonly class GitHubChangeAuthorship implements ChangeAuthorship
{
    public function __construct(
        private TrustAnswers $github,
        private CheckedInstallationTokens $tokens,
        private SteeringIdentities $identities,
    ) {
    }

    public function isCreatedByIntakt(ChangeId $change): bool
    {
        try {
            $login = $this->authorOf(PullRequestAddress::of($change));

            return $this->identities->isBot($login) && $this->identities->isOwn($login);
        } catch (ChangeNotFound|TrustNotChecked|InstallationNotReadBack|InstallationDoesNotMatchConfiguration|InstallationTokenNotMinted $unanswered) {
            throw new ForgeReadFailed(sprintf('The author of %s could not be checked against this App: %s', $change->value, $unanswered->getMessage()), 0, $unanswered);
        }
    }

    private function authorOf(PullRequestAddress $pull): string
    {
        $user = $this->github->ofInstallation($pull->pullRequestPath(), $this->tokens->current()->value)['user'] ?? null;
        $login = is_array($user) ? ($user['login'] ?? null) : null;

        // An empty login is no author: answering "not Intakt" from it would be recorded for good.
        return is_string($login) && '' !== $login ? $login : throw TrustNotChecked::becauseTheAnswerIsInvalid();
    }
}
