<?php

declare(strict_types=1);

namespace Intakt\Core\Mcp\Infrastructure;

use Intakt\Core\Mcp\Contract\ToolAccess;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

// The voter never sees the arguments of a call, because a model could otherwise argue its way to a write; a write needs the config and the owner's token.
final readonly class ToolAccessVoter implements VoterInterface
{
    public function __construct(
        private ToolAccess $highestAllowed,
    ) {
    }

    public function vote(TokenInterface $token, mixed $subject, array $attributes, ?Vote $vote = null): int
    {
        $result = self::ACCESS_ABSTAIN;

        foreach ($attributes as $attribute) {
            $access = is_string($attribute) ? ToolAccess::tryFrom($attribute) : null;

            if (null === $access) {
                continue;
            }

            if ($this->grants($access, $token)) {
                return self::ACCESS_GRANTED;
            }

            $result = self::ACCESS_DENIED;
        }

        return $result;
    }

    private function grants(ToolAccess $access, TokenInterface $token): bool
    {
        if (ToolAccess::Read === $access) {
            return true;
        }

        return ToolAccess::Write === $this->highestAllowed && in_array(OwnerTokenHandler::OWNER_ROLE, $token->getRoleNames(), true);
    }
}
