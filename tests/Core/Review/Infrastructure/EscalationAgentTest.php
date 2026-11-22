<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Review\Contract\EscalationFailed;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\EscalationRequest;
use Intakt\Core\Review\Contract\EscalationReviewed;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\FencedText;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\PromptAssembly;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\UntrustedSource;
use Intakt\Core\Review\Infrastructure\CitedCodeNotShown;
use Intakt\Core\Review\Infrastructure\DiffPaths;
use Intakt\Core\Review\Infrastructure\EscalationAgent;
use Intakt\Core\Review\Infrastructure\EscalationPrompt;
use Intakt\Core\Review\Infrastructure\FindingKeys;
use Intakt\Plugin\Vendor\Contract\EscalationReviews;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\Vendor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Uid\Uuid;

#[CoversClass(EscalationAgent::class)]
#[CoversClass(EscalationReviewed::class)]
#[CoversClass(EscalationFailed::class)]
#[UsesClass(EscalationPrompt::class)]
#[UsesClass(EscalationRequest::class)]
#[UsesClass(EscalationVerdict::class)]
#[UsesClass(CitedCodeNotShown::class)]
#[UsesClass(DiffPaths::class)]
#[UsesClass(FindingKeys::class)]
#[UsesClass(Fence::class)]
#[UsesClass(FencedText::class)]
#[UsesClass(PromptAssembly::class)]
#[UsesClass(Finding::class)]
final class EscalationAgentTest extends TestCase
{
    private const string ROUND = '0192a000-0000-7000-8000-000000000002';
    private const string DIFF = "diff --git a/src/Bus.php b/src/Bus.php\n+send();\n";

    public function testAConfirmedAnswerYieldsAReviewedEscalationWithItsVerdict(): void
    {
        $escalation = $this->agent($this->answering(new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops the message.')))->review('alpha', Uuid::fromString(self::ROUND), $this->request(self::DIFF));

        self::assertEquals(new EscalationReviewed(new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops the message.')), $escalation);
    }

    public function testReasonsHoldingASecretAreRedactedAndTrimmedBeforeTheyAreReturned(): void
    {
        $escalation = $this->agent($this->answering(new EscalationVerdict(EscalationOutcome::Rejected, ' The retry sends it with sk-held. ')))->review('alpha', Uuid::fromString(self::ROUND), $this->request(self::DIFF));

        self::assertEquals(new EscalationReviewed(new EscalationVerdict(EscalationOutcome::Rejected, 'The retry sends it with [redacted].')), $escalation);
    }

    public function testAnUnreadableAnswerYieldsAFailedEscalationWithIntaktsOwnReason(): void
    {
        $escalation = $this->agent($this->answering(null))->review('alpha', Uuid::fromString(self::ROUND), $this->request(self::DIFF));

        self::assertEquals(new EscalationFailed(EscalationAgent::UNREADABLE), $escalation);
    }

    public function testAnUnmappedStrongTierYieldsAFailedEscalationAndCallsNoModel(): void
    {
        $escalation = $this->agent($this->callingNoModel(), mapsTheStrongTier: false)->review('alpha', Uuid::fromString(self::ROUND), $this->request(self::DIFF));

        self::assertEquals(new EscalationFailed('project "alpha" maps no model to the "strong" tier, which the escalation review needs'), $escalation);
    }

    public function testAFindingWhoseCitedCodeIsNotInTheDiffYieldsAFailedEscalationAndCallsNoModel(): void
    {
        $escalation = $this->agent($this->callingNoModel())->review('alpha', Uuid::fromString(self::ROUND), $this->request("diff --git a/src/Other.php b/src/Other.php\n+x();\n"));

        self::assertEquals(new EscalationFailed(CitedCodeNotShown::notInDiff()->getMessage()), $escalation);
    }

    public function testTheCallGoesToTheStrongTierWithTheRoundAndThePromptOfTheFinding(): void
    {
        $escalations = $this->createMock(EscalationReviews::class);
        $escalations->expects(self::once())->method('escalate')
            ->with('alpha', Uuid::fromString(self::ROUND), self::callback(static fn (MessageBag $messages): bool => 3 === count($messages->getMessages())
                && str_contains((string) $messages->getUserMessage()?->asText(), '<<<UNTRUSTED '.UntrustedSource::EscalatedFinding->value)))
            ->willReturn(new EscalationVerdict(EscalationOutcome::Confirmed, 'Real.'));
        $vendor = $this->createMock(Vendor::class);
        $vendor->expects(self::once())->method('mapsAModelTo')->with('alpha', Tier::Strong)->willReturn(true);

        $escalation = new EscalationAgent(new EscalationPrompt(new Fence()), $vendor, $escalations, $this->redactor())->review('alpha', Uuid::fromString(self::ROUND), $this->request(self::DIFF));

        self::assertInstanceOf(EscalationReviewed::class, $escalation);
    }

    public function testAProviderErrorFromTheCallIsThrownUnchanged(): void
    {
        $limited = new ProviderRateLimited(30, new RuntimeException('limited'));
        $escalations = self::createStub(EscalationReviews::class);
        $escalations->method('escalate')->willThrowException($limited);

        try {
            $_ = $this->agent($escalations)->review('alpha', Uuid::fromString(self::ROUND), $this->request(self::DIFF));
            self::fail('A provider error must reach the queue, never become a failed escalation.');
        } catch (ProviderRateLimited $thrown) {
            self::assertSame($limited, $thrown);
        }
    }

    private function answering(?EscalationVerdict $verdict): EscalationReviews
    {
        $escalations = self::createStub(EscalationReviews::class);
        $escalations->method('escalate')->willReturn($verdict);

        return $escalations;
    }

    private function callingNoModel(): EscalationReviews
    {
        $escalations = $this->createMock(EscalationReviews::class);
        $escalations->expects(self::never())->method('escalate');

        return $escalations;
    }

    private function agent(EscalationReviews $escalations, bool $mapsTheStrongTier = true): EscalationAgent
    {
        $vendor = self::createStub(Vendor::class);
        $vendor->method('mapsAModelTo')->willReturn($mapsTheStrongTier);

        return new EscalationAgent(new EscalationPrompt(new Fence()), $vendor, $escalations, $this->redactor());
    }

    private function redactor(): Redactor
    {
        $redactor = self::createStub(Redactor::class);
        $redactor->method('redact')->willReturnCallback(static fn (string $text): string => str_replace('sk-held', '[redacted]', $text));

        return $redactor;
    }

    private function request(string $diff): EscalationRequest
    {
        return new EscalationRequest(new Finding('F1', Severity::P1, 'src/Bus.php', 3, null, 'The bus loses a message.', FixableBy::Builder, true, null, null), $diff);
    }
}
