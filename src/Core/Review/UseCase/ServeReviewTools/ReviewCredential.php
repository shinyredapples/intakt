<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ServeReviewTools;

use Override;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Uid\Uuid;

// The round comes from a credential signed by Intakt, never from a tool argument, because a model controls the arguments and must not pick another review's head.
final readonly class ReviewCredential implements AccessTokenHandlerInterface
{
    public const string REVIEWER_ROLE = 'ROLE_MCP_REVIEWER';

    // A purpose of its own, so a signature made with the same secret for anything else never passes as a review credential.
    private const string PURPOSE = 'intakt-review-tools:';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        #[SensitiveParameter]
        private string $secret,
    ) {
    }

    public function issueFor(string $roundId): string
    {
        if ('' === $this->secret) {
            throw ReviewCredentialNeedsSecret::whileTheSecretIsEmpty();
        }

        return $roundId.'.'.$this->signature($roundId);
    }

    #[Override]
    public function getUserBadgeFrom(#[SensitiveParameter] string $accessToken): UserBadge
    {
        $roundId = $this->roundOf($accessToken);

        return new UserBadge($roundId, static fn (string $round): InMemoryUser => new InMemoryUser($round, null, [self::REVIEWER_ROLE]));
    }

    // A UUID holds no dot, so the first dot is the boundary between the round and its signature.
    private function roundOf(string $credential): string
    {
        [$roundId, $signature] = explode('.', $credential, 2) + ['', ''];

        // Anyone can sign with an empty secret, so none is accepted while APP_SECRET is empty.
        if ('' === $this->secret || !Uuid::isValid($roundId) || !hash_equals($this->signature($roundId), $signature)) {
            throw new BadCredentialsException('The bearer token is no review credential.');
        }

        return $roundId;
    }

    private function signature(string $roundId): string
    {
        return hash_hmac('sha256', self::PURPOSE.$roundId, $this->secret);
    }
}
