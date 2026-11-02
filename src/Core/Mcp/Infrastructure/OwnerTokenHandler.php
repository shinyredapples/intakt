<?php

declare(strict_types=1);

namespace Intakt\Core\Mcp\Infrastructure;

use Override;
use SensitiveParameter;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

// The token comes from the request header only, because a model controls tool arguments.
final readonly class OwnerTokenHandler implements AccessTokenHandlerInterface
{
    public const string OWNER_ROLE = 'ROLE_MCP_OWNER';

    public function __construct(
        #[SensitiveParameter]
        private string $configuredToken,
    ) {
    }

    #[Override]
    public function getUserBadgeFrom(#[SensitiveParameter] string $accessToken): UserBadge
    {
        // An empty configured token means no write access is set up, so no header may match it.
        if ('' === $this->configuredToken || !hash_equals($this->configuredToken, $accessToken)) {
            throw new BadCredentialsException('The bearer token is not the owner token.');
        }

        return new UserBadge('mcp-owner', static fn (): InMemoryUser => new InMemoryUser('mcp-owner', null, [self::OWNER_ROLE]));
    }
}
