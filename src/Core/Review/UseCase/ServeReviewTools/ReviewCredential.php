<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ServeReviewTools;

use LogicException;
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

    // Held by a client whose model meets the answers without Intakt's toolbox, so the server fences them itself.
    public const string FENCED_ROLE = 'ROLE_MCP_FENCED_ANSWERS';

    // A purpose of its own, so a signature made with the same secret for anything else never passes as a review credential.
    private const string PURPOSE = 'intakt-review-tools:';

    // Signed apart, so the answers' form comes from Intakt's signature and a client cannot drop the fence by sending another header.
    private const string FENCED_PURPOSE = 'intakt-review-tools-fenced:';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        #[SensitiveParameter]
        private string $secret,
    ) {
    }

    public function issueFor(string $roundId): string
    {
        return $this->issued($roundId, self::PURPOSE);
    }

    // For a subscription CLI, which reads the answers outside Intakt's toolbox and so gets them fenced and with the note of a cut.
    public function issueFencedFor(string $roundId): string
    {
        return $this->issued($roundId, self::FENCED_PURPOSE);
    }

    #[Override]
    public function getUserBadgeFrom(#[SensitiveParameter] string $accessToken): UserBadge
    {
        [$roundId, $signature] = $this->partsOf($accessToken);
        $roles = match (true) {
            $this->signs($roundId, self::PURPOSE, $signature) => [self::REVIEWER_ROLE],
            $this->signs($roundId, self::FENCED_PURPOSE, $signature) => [self::REVIEWER_ROLE, self::FENCED_ROLE],
            default => throw new BadCredentialsException('The bearer token is no review credential.'),
        };

        return new UserBadge($roundId, static fn (string $round): InMemoryUser => new InMemoryUser($round, null, $roles));
    }

    private function issued(string $roundId, string $purpose): string
    {
        if ('' === $this->secret) {
            throw new LogicException('APP_SECRET is empty, so no review credential can be issued.');
        }

        return $roundId.'.'.$this->signature($roundId, $purpose);
    }

    // A UUID holds no dot, so the first dot is the boundary between the round and its signature.
    /**
     * @return array{string, string}
     */
    private function partsOf(string $credential): array
    {
        [$roundId, $signature] = explode('.', $credential, 2) + ['', ''];

        return [$roundId, $signature];
    }

    // Anyone can sign with an empty secret, so none is accepted while APP_SECRET is empty.
    private function signs(string $roundId, string $purpose, string $signature): bool
    {
        return '' !== $this->secret && Uuid::isValid($roundId) && hash_equals($this->signature($roundId, $purpose), $signature);
    }

    private function signature(string $roundId, string $purpose): string
    {
        return hash_hmac('sha256', $purpose.$roundId, $this->secret);
    }
}
