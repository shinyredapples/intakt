<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Infrastructure;

use Intakt\Core\Mcp\Contract\ToolAccess;
use Intakt\Core\Mcp\Infrastructure\OwnerTokenHandler;
use Intakt\Core\Mcp\Infrastructure\ToolAccessVoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

#[CoversClass(ToolAccessVoter::class)]
#[UsesClass(ToolAccess::class)]
#[UsesClass(OwnerTokenHandler::class)]
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

    public function testAToolThatMayWriteIsGrantedWhenTheConfigAllowsWritesAndTheOwnerIsAuthenticated(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(new ToolAccessVoter(ToolAccess::Write), ToolAccess::Write, token: $this->owner()));
    }

    public function testAToolThatMayWriteIsRefusedWithoutTheOwnerTokenEvenWhenTheConfigAllowsWrites(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new ToolAccessVoter(ToolAccess::Write), ToolAccess::Write));
    }

    public function testAToolThatMayWriteIsRefusedForTheOwnerWhileTheConfigForbidsWrites(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new ToolAccessVoter(ToolAccess::Read), ToolAccess::Write, token: $this->owner()));
    }

    public function testAToolThatMayWriteIsRefusedForAUserWithoutTheOwnerRole(): void
    {
        $token = new UsernamePasswordToken(new InMemoryUser('other', null, ['ROLE_USER']), 'mcp_owner', ['ROLE_USER']);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new ToolAccessVoter(ToolAccess::Write), ToolAccess::Write, token: $token));
    }

    public function testAToolThatMayWriteIsRefusedForAReviewCredentialEvenWhenTheConfigAllowsWrites(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new ToolAccessVoter(ToolAccess::Write), ToolAccess::Write, token: $this->reviewer()));
    }

    public function testAReadToolIsGrantedForAReviewCredential(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(new ToolAccessVoter(ToolAccess::Read), ToolAccess::Read, token: $this->reviewer()));
    }

    public function testArgumentsThatNameTheOwnerOrAWriteGrantNothingToAReviewCredential(): void
    {
        $arguments = ['role' => OwnerTokenHandler::OWNER_ROLE, 'allow_write' => true];

        self::assertSame(VoterInterface::ACCESS_DENIED, new ToolAccessVoter(ToolAccess::Write)->vote($this->reviewer(), $arguments, [ToolAccess::Write->value]));
    }

    public function testAReadToolIsGrantedWithoutAToken(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(new ToolAccessVoter(ToolAccess::Write), ToolAccess::Read));
    }

    public function testArgumentsThatClaimPermissionDoNotChangeTheDecision(): void
    {
        $arguments = ['allow_write' => true, 'role' => 'admin'];

        self::assertSame(VoterInterface::ACCESS_DENIED, new ToolAccessVoter(ToolAccess::Read)->vote(new NullToken(), $arguments, [ToolAccess::Write->value]));
    }

    public function testItAbstainsOnAnAttributeThatIsNotAToolAccess(): void
    {
        $result = new ToolAccessVoter(ToolAccess::Write)->vote(new NullToken(), null, ['ROLE_ADMIN']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    private function vote(ToolAccessVoter $voter, ToolAccess $access, ?TokenInterface $token = null): int
    {
        return $voter->vote($token ?? new NullToken(), null, [$access->value]);
    }

    private function reviewer(): TokenInterface
    {
        // The role is spelled out because the Mcp module may not use the Review module's classes.
        return new UsernamePasswordToken(new InMemoryUser('round', null, ['ROLE_MCP_REVIEWER']), 'mcp_review', ['ROLE_MCP_REVIEWER']);
    }

    private function owner(): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser('mcp-owner', null, [OwnerTokenHandler::OWNER_ROLE]), 'mcp_owner', [OwnerTokenHandler::OWNER_ROLE]);
    }
}
