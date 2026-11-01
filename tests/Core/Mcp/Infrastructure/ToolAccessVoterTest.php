<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Infrastructure;

use Intakt\Core\Mcp\Contract\ToolAccess;
use Intakt\Core\Mcp\Infrastructure\ToolAccessVoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[CoversClass(ToolAccessVoter::class)]
#[UsesClass(ToolAccess::class)]
final class ToolAccessVoterTest extends TestCase
{
    public function testAReadToolIsGranted(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(new ToolAccessVoter(ToolAccess::Read), ToolAccess::Read));
    }

    public function testAToolThatMayWriteIsRefusedWhileTheConfigForbidsWrites(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new ToolAccessVoter(ToolAccess::Read), ToolAccess::Write));
    }

    public function testAToolThatMayWriteIsGrantedWhenTheConfigAllowsWrites(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(new ToolAccessVoter(ToolAccess::Write), ToolAccess::Write));
    }

    public function testArgumentsThatClaimPermissionDoNotChangeTheDecision(): void
    {
        $arguments = ['allow_write' => true, 'role' => 'admin'];

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new ToolAccessVoter(ToolAccess::Read), ToolAccess::Write, $arguments));
    }

    public function testItAbstainsOnAnAttributeThatIsNotAToolAccess(): void
    {
        $result = new ToolAccessVoter(ToolAccess::Write)->vote(new NullToken(), null, ['ROLE_ADMIN']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    /**
     * @param array<string, mixed>|null $subject
     */
    private function vote(ToolAccessVoter $voter, ToolAccess $access, ?array $subject = null): int
    {
        return $voter->vote(new NullToken(), $subject, [$access->value]);
    }
}
