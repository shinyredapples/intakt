<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Domain\ChangeNotShown;
use Intakt\Plugin\GitHubForge\Domain\CiFinding;
use Intakt\Plugin\GitHubForge\Domain\CiScanResult;
use Intakt\Plugin\GitHubForge\Domain\HeldChange;
use Intakt\Plugin\GitHubForge\Domain\PushRequest;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFinding;
use Intakt\Plugin\GitHubForge\Domain\WorkflowRisk;
use Intakt\Plugin\GitHubForge\Domain\WorkflowSide;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PushRequest::class)]
#[UsesClass(ChangeNotShown::class)]
#[UsesClass(CiFinding::class)]
#[UsesClass(CiScanResult::class)]
#[UsesClass(HeldChange::class)]
#[UsesClass(WorkflowFinding::class)]
#[UsesClass(WorkflowRisk::class)]
#[UsesClass(WorkflowSide::class)]
final class PushRequestTest extends TestCase
{
    private const string HEAD = '0123456789abcdef0123456789abcdef01234567';
    private const string DIFF = "diff --git a/bin/check b/bin/check\n--- a/bin/check\n+++ b/bin/check\n@@ -1 +1 @@\n-old\n+new\n";

    public function testTheRequestShowsTheFindingsTheCompleteDiffTheApprovalAndItsMarker(): void
    {
        $body = new PushRequest()->for(new HeldChange(self::HEAD, self::DIFF), $this->scan());

        self::assertStringContainsString("```text\nmain .github/workflows/ci.yml line 9: unreadable: the runner comes from a variable\nci bin/check\n```", $body);
        self::assertStringContainsString("```diff\n".self::DIFF.'```', $body);
        self::assertStringContainsString("\n    approve ".self::HEAD."\n", $body);
        self::assertStringEndsWith("\n<!-- intakt-push-request: ".self::HEAD.' -->', $body);
    }

    public function testADiffWithBackticksGetsALongerFenceSoItCannotCloseIt(): void
    {
        $diff = "+````\n+**forged**\n";

        $body = new PushRequest()->for(new HeldChange(self::HEAD, $diff), $this->scan());

        self::assertStringContainsString("`````diff\n".$diff.'`````', $body);
    }

    public function testARequestOfExactlyTheCommentLimitIsShown(): void
    {
        $request = new PushRequest();
        $short = mb_strlen($request->for(new HeldChange(self::HEAD, ''), $this->scan()));

        $body = $request->for(new HeldChange(self::HEAD, str_repeat('é', PushRequest::MAX_CHARACTERS - $short)), $this->scan());

        self::assertSame(PushRequest::MAX_CHARACTERS, mb_strlen($body));
    }

    public function testARequestOneCharacterOverTheLimitIsNotShownAndMustBeSplit(): void
    {
        $request = new PushRequest();
        $short = mb_strlen($request->for(new HeldChange(self::HEAD, ''), $this->scan()));

        $this->expectExceptionObject(ChangeNotShown::because(sprintf('the request with its diff is %d characters, more than one comment holds (65536), so the change must be split', PushRequest::MAX_CHARACTERS + 1)));

        (void) $request->for(new HeldChange(self::HEAD, str_repeat('x', PushRequest::MAX_CHARACTERS + 1 - $short)), $this->scan());
    }

    public function testADiffThatIsNotUtf8IsNotShown(): void
    {
        $this->expectExceptionObject(ChangeNotShown::because('the diff is not UTF-8 text, so a comment cannot show it'));

        (void) new PushRequest()->for(new HeldChange(self::HEAD, "+\xff\n"), $this->scan());
    }

    public function testADiffWithANulByteIsNotShown(): void
    {
        $this->expectExceptionObject(ChangeNotShown::because('the diff holds a NUL byte, so a comment cannot show it'));

        (void) new PushRequest()->for(new HeldChange(self::HEAD, "+a\0b\n"), $this->scan());
    }

    private function scan(): CiScanResult
    {
        return new CiScanResult([new CiFinding(WorkflowSide::Main, '.github/workflows/ci.yml', new WorkflowFinding(WorkflowRisk::Unreadable, 9, 'the runner comes from a variable'))], ['bin/check']);
    }
}
