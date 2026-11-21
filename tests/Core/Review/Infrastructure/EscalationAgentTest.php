<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\EscalationRequest;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\FencedText;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\PromptAssembly;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\UntrustedSource;
use Intakt\Core\Review\Infrastructure\EscalationAgent;
use Intakt\Core\Review\Infrastructure\EscalationPrompt;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(EscalationAgent::class)]
#[CoversClass(EscalationPrompt::class)]
#[UsesClass(EscalationRequest::class)]
#[UsesClass(EscalationVerdict::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Fence::class)]
#[UsesClass(FencedText::class)]
#[UsesClass(PromptAssembly::class)]
#[UsesClass(PromptCut::class)]
final class EscalationAgentTest extends KernelTestCase
{
    /**
     * @var list<string>
     */
    private array $sent = [];

    public function testAConfirmedAnswerIsTheVerdictWithItsReasons(): void
    {
        $verdict = $this->ask('{"outcome":"confirmed","reasons":"The bus drops the message."}');

        self::assertEquals(new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops the message.'), $verdict);
    }

    public function testARejectedAnswerIsTheVerdictWithItsReasons(): void
    {
        $verdict = $this->ask('{"outcome":"rejected","reasons":"The retry delivers it."}');

        self::assertEquals(new EscalationVerdict(EscalationOutcome::Rejected, 'The retry delivers it.'), $verdict);
    }

    public function testAnOutcomeThatIsNeitherConfirmedNorRejectedIsNoVerdict(): void
    {
        self::assertNull($this->ask('{"outcome":"maybe","reasons":"Unsure."}'));
    }

    public function testAnAnswerWithBlankReasonsIsNoVerdict(): void
    {
        self::assertNull($this->ask('{"outcome":"confirmed","reasons":"  "}'));
    }

    public function testProseInsteadOfJsonIsNoVerdict(): void
    {
        self::assertNull($this->ask('I confirm the finding.'));
    }

    public function testTheTaskTheDiffAndTheFindingReachTheModelFencedAndNothingElseOfTheChange(): void
    {
        $this->ask('{"outcome":"confirmed","reasons":"Real."}');

        foreach ([UntrustedSource::Task, UntrustedSource::Diff, UntrustedSource::EscalatedFinding] as $source) {
            self::assertMatchesRegularExpression('/<<<UNTRUSTED '.$source->value.' fence-[0-9a-f]{16}>>>/', $this->sent[0]);
        }

        self::assertStringContainsString('F1 [P1] src\/Bus.php:3\nrequirement: -\nThe bus loses a message.', $this->sent[0]);
        self::assertStringContainsString('You are the escalation review for one finding of severity P1', $this->sent[0]);
        self::assertStringNotContainsString('"tools"', $this->sent[0]);
    }

    private function ask(string $answer): ?EscalationVerdict
    {
        $platform = MockPlatformFactory::createPlatform(function (mixed $model, mixed $input, array $options = []) use ($answer): string {
            $this->sent[] = json_encode([$input, $options], JSON_THROW_ON_ERROR);

            return $answer;
        }, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $finding = new Finding('F1', Severity::P1, 'src/Bus.php', 3, null, 'The bus loses a message.', FixableBy::Builder, true, null, null);
        $request = new EscalationRequest(new ChangeUnderReview('alpha', 'Add a bus.', "diff --git a/src/Bus.php b/src/Bus.php\n+send();\n"), $finding, new UuidV7());

        return new EscalationAgent(new EscalationPrompt(new PromptCut(new Fence())))->review($request, $platform, 'strong-model');
    }
}
