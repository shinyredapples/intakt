<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Domain\IssueComment;
use Intakt\Plugin\GitHubForge\Domain\PushApproval;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PushApproval::class)]
#[UsesClass(IssueComment::class)]
final class PushApprovalTest extends TestCase
{
    private const string HEAD = '0123456789abcdef0123456789abcdef01234567';

    public function testTheOwnersApprovalOfExactlyThisHeadLetsItPush(): void
    {
        self::assertTrue($this->given(new IssueComment('owner', true, false, false, ' approve '.self::HEAD."\n")));
    }

    public function testTheOwnersLoginAndTheCommitIdMatchInAnyCase(): void
    {
        self::assertTrue($this->given(new IssueComment('Owner', true, false, false, 'Approve '.strtoupper(self::HEAD))));
    }

    public function testAnApprovalOfAnotherHeadDoesNotCount(): void
    {
        self::assertFalse($this->given(new IssueComment('owner', true, false, false, 'approve 1123456789abcdef0123456789abcdef01234567')));
    }

    public function testAShortCommitIdDoesNotCountBecauseABuilderCanMatchIt(): void
    {
        self::assertFalse($this->given(new IssueComment('owner', true, false, false, 'approve 0123456')));
    }

    public function testAnotherAccountsApprovalDoesNotCount(): void
    {
        self::assertFalse($this->given(new IssueComment('maintainer', true, false, false, 'approve '.self::HEAD)));
    }

    public function testAnAppNamedLikeTheOwnerDoesNotCount(): void
    {
        self::assertFalse($this->given(new IssueComment('owner', false, false, true, 'approve '.self::HEAD)));
    }

    public function testAnEditedCommentDoesNotCount(): void
    {
        self::assertFalse($this->given(new IssueComment('owner', true, true, false, 'approve '.self::HEAD)));
    }

    public function testAnApprovalInsideOtherTextDoesNotCount(): void
    {
        self::assertFalse($this->given(new IssueComment('owner', true, false, false, 'Not yet: approve '.self::HEAD)));
    }

    public function testWithoutAnOwnerAccountNoApprovalCounts(): void
    {
        self::assertFalse(new PushApproval()->isGivenIn([new IssueComment('', true, false, false, 'approve '.self::HEAD)], '', self::HEAD));
    }

    private function given(IssueComment $comment): bool
    {
        return new PushApproval()->isGivenIn([new IssueComment('owner', true, false, false, 'Looks fine.'), $comment], 'owner', self::HEAD);
    }
}
