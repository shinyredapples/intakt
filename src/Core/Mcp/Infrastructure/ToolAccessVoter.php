<?php

declare(strict_types=1);

namespace Intakt\Core\Mcp\Infrastructure;

use Intakt\Core\Mcp\Contract\ToolAccess;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Decides from Intakt's own config only: it never sees the arguments of a call, so a model cannot argue its way to a write.
 */
final readonly class ToolAccessVoter implements VoterInterface
{
    public function __construct(
        private bool $writeToolsAllowed,
    ) {
    }

    public function vote(TokenInterface $token, mixed $subject, array $attributes, ?Vote $vote = null): int
    {
        $result = self::ACCESS_ABSTAIN;

        foreach ($attributes as $attribute) {
            if (!$attribute instanceof ToolAccess) {
                continue;
            }

            if ($this->grants($attribute)) {
                return self::ACCESS_GRANTED;
            }

            $result = self::ACCESS_DENIED;
        }

        return $result;
    }

    private function grants(ToolAccess $access): bool
    {
        return ToolAccess::Read === $access || $this->writeToolsAllowed;
    }
}
