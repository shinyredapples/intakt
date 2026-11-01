<?php

declare(strict_types=1);

namespace Intakt\Core\Mcp\Infrastructure;

use Intakt\Core\Mcp\Contract\ToolAccess;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

// The voter never sees the arguments of a call, because a model could otherwise argue its way to a write.
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

            if ($this->grants($access)) {
                return self::ACCESS_GRANTED;
            }

            $result = self::ACCESS_DENIED;
        }

        return $result;
    }

    private function grants(ToolAccess $access): bool
    {
        return ToolAccess::Read === $access || ToolAccess::Write === $this->highestAllowed;
    }
}
