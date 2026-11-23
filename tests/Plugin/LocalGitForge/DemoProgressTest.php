<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\EveryProviderOut;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ProviderOutage;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Review\Contract\AwaitedReviewer;
use Intakt\Core\Review\Contract\FinishedState;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\SampleChange;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\DemoProgress;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\DemoProviderBusy;
use Intakt\Plugin\Vendor\Contract\Vendor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

#[CoversClass(DemoProgress::class)]
#[CoversClass(DemoProviderBusy::class)]
#[UsesClass(AwaitedReviewer::class)]
#[UsesClass(RoundProgress::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(EveryAccountCoolingDown::class)]
#[UsesClass(EveryProviderOut::class)]
#[UsesClass(ProviderOutage::class)]
final class DemoProgressTest extends TestCase
{
    private const string KIMI = 'moonshotai/Kimi-K3.1-Pro:deepinfra';

    private const string DEEPSEEK = 'deepseek-ai/DeepSeek-V4-Pro-0813:deepinfra';

    private MockClock $clock;

    private BufferedOutput $output;

    public function testTheFirstPollNamesEveryReviewerItWaitsOnWithTheModelThatAnswers(): void
    {
        $this->progress($this->answering())->reached($this->waitingOnBoth());

        self::assertSame(['Waiting on the correctness reviewer ('.self::KIMI.') and the security reviewer ('.self::DEEPSEEK.').'], $this->lines());
    }

    public function testAPollThatChangedNothingPrintsNothingUntilHalfAMinuteHasPassed(): void
    {
        $progress = $this->progress($this->answering());

        $progress->reached($this->waitingOnBoth());
        $progress->reached($this->waitingOnBoth());
        $this->clock->sleep(30);
        $progress->reached($this->waitingOnBoth());

        self::assertSame([
            'Waiting on the correctness reviewer ('.self::KIMI.') and the security reviewer ('.self::DEEPSEEK.').',
            'Still waiting after 30 s on the correctness reviewer ('.self::KIMI.') and the security reviewer ('.self::DEEPSEEK.').',
        ], $this->lines());
    }

    public function testAReviewerThatAnsweredLeavesTheLineAndTheJudgeComesLast(): void
    {
        $progress = $this->progress($this->answering());

        $progress->reached($this->waitingOnBoth());
        $progress->reached(new RoundProgress(1, 2, false, [], 1, [new AwaitedReviewer('correctness', Tier::Strong, 'reviewer')]));
        $progress->reached(new RoundProgress(2, 2, false));
        $progress->reached(new RoundProgress(2, 2, true));

        self::assertSame([
            'Waiting on the correctness reviewer ('.self::KIMI.') and the security reviewer ('.self::DEEPSEEK.').',
            'Waiting on the correctness reviewer ('.self::KIMI.').',
            'Waiting on the judge ('.self::KIMI.').',
        ], $this->lines());
    }

    // The judge completes a round without findings without a model call, so a rate limit on its tier is no reason to stop.
    public function testAPanelThatFinishedWithoutFindingsReportsNothingForTheJudge(): void
    {
        $this->progress($this->judgeLimited(new EveryAccountCoolingDown(Provider::OpenAiCompatible, 60)))->reached(new RoundProgress(2, 2, false, [], 2, [], false));

        self::assertSame([], $this->lines());
    }

    public function testAPanelThatFinishedWithFindingsStopsTheDemoWhenTheJudgeIsRateLimited(): void
    {
        $busy = $this->failureOf(fn () => $this->progress($this->judgeLimited(new EveryAccountCoolingDown(Provider::OpenAiCompatible, 60)))->reached(new RoundProgress(2, 2, false)));

        self::assertInstanceOf(DemoProviderBusy::class, $busy);
    }

    public function testAModelThatMovedToItsFallbackIsNamedAgain(): void
    {
        $tiers = self::createStub(TierInvoker::class);
        $tiers->method('answering')->willReturnOnConsecutiveCalls(new ResolvedModel(Provider::OpenAiCompatible, self::KIMI), new ResolvedModel(Provider::OpenAiCompatible, 'moonshotai/Kimi-K3.1-Pro:together'));
        $progress = $this->progress($tiers);
        $correctness = new RoundProgress(0, 1, false, [], 0, [new AwaitedReviewer('correctness', Tier::Strong, 'reviewer')]);

        $progress->reached($correctness);
        $progress->reached($correctness);

        self::assertSame(['Waiting on the correctness reviewer ('.self::KIMI.').', 'Waiting on the correctness reviewer (moonshotai/Kimi-K3.1-Pro:together).'], $this->lines());
    }

    public function testAReviewerWhoseOnlyProviderIsRateLimitedStopsTheDemoWithAPlainMessage(): void
    {
        $busy = $this->failureOf(fn () => $this->progress($this->limited(new EveryAccountCoolingDown(Provider::OpenAiCompatible, 60)))->reached($this->waitingOnBoth()));

        self::assertInstanceOf(DemoProviderBusy::class, $busy);
        self::assertStringStartsWith('The correctness reviewer cannot get an answer: openai_compatible is rate-limited for 60 s and no fallback is free. Run intakt:demo again in a few minutes', $busy->getMessage());
    }

    public function testTheRateLimitMessagePointsAtTheDemoSectionOfTheReferenceManual(): void
    {
        $busy = $this->failureOf(fn () => $this->progress($this->limited(new EveryAccountCoolingDown(Provider::OpenAiCompatible, 60)))->reached($this->waitingOnBoth()));

        self::assertStringEndsWith('see "The demo with docker compose" in docs/reference/user-manual.md).', $busy->getMessage());
    }

    public function testAReviewerWhoseModelAndFallbackAreRateLimitedStopsTheDemo(): void
    {
        $out = new EveryProviderOut([ProviderOutage::coolingDown(Provider::OpenAiCompatible, 42)]);

        $busy = $this->failureOf(fn () => $this->progress($this->limited($out))->reached($this->waitingOnBoth()));

        self::assertInstanceOf(DemoProviderBusy::class, $busy);
        self::assertStringStartsWith('The correctness reviewer cannot get an answer: openai_compatible is rate-limited for 42 s and no fallback is free.', $busy->getMessage());
    }

    // Forced order: the worker records the reviewer blocked by its cooling pin before the next poll, so the reviewer is no longer awaited when the demo looks again.
    public function testAReviewerBlockedByACoolingPinBetweenTwoPollsStillStopsTheDemo(): void
    {
        $tiers = self::createStub(TierInvoker::class);
        $tiers->method('answering')->willReturnOnConsecutiveCalls(
            new ResolvedModel(Provider::OpenAiCompatible, self::KIMI),
            self::throwException(new EveryProviderOut([ProviderOutage::coolingDown(Provider::OpenAiCompatible, 60)])),
        );
        $progress = $this->progress($tiers);
        $progress->reached(new RoundProgress(0, 1, false, [], 1, [new AwaitedReviewer('correctness', Tier::Strong, 'reviewer')]));

        $busy = $this->failureOf(static fn () => $progress->reached(new RoundProgress(1, 1, false, ['reviewer' => FinishedState::Blocked], 1, [], false)));

        self::assertInstanceOf(DemoProviderBusy::class, $busy);
        self::assertStringStartsWith('The correctness reviewer cannot get an answer: openai_compatible is rate-limited for 60 s', $busy->getMessage());
    }

    public function testAReviewerBlockedWhileItsTierIsFreeEndsTheDemoAsUsual(): void
    {
        $progress = $this->progress($this->answering());
        $progress->reached(new RoundProgress(0, 1, false, [], 1, [new AwaitedReviewer('correctness', Tier::Strong, 'reviewer')]));

        $progress->reached(new RoundProgress(1, 1, false, ['reviewer' => FinishedState::Blocked], 1, [], false));

        self::assertSame(['Waiting on the correctness reviewer ('.self::KIMI.').'], $this->lines());
    }

    public function testAReviewerThatAnsweredBetweenTwoPollsIsNotAskedAboutAgain(): void
    {
        $tiers = self::createStub(TierInvoker::class);
        $tiers->method('answering')->willReturnOnConsecutiveCalls(
            new ResolvedModel(Provider::OpenAiCompatible, self::KIMI),
            self::throwException(new EveryAccountCoolingDown(Provider::OpenAiCompatible, 60)),
        );
        $progress = $this->progress($tiers);
        $progress->reached(new RoundProgress(0, 1, false, [], 1, [new AwaitedReviewer('correctness', Tier::Strong, 'reviewer')]));

        $progress->reached(new RoundProgress(1, 1, false, ['reviewer' => FinishedState::Verdict], 1, [], false));

        self::assertSame(['Waiting on the correctness reviewer ('.self::KIMI.').'], $this->lines());
    }

    // Forced order: between two polls the correctness reviewer is blocked while its tier is free, and the security reviewer delivers its verdict before its tier starts to cool.
    public function testAReviewerThatDeliveredItsVerdictDoesNotStopTheDemoWhenItsTierCoolsAfterwards(): void
    {
        $cooling = false;
        $tiers = self::createStub(TierInvoker::class);
        $tiers->method('answering')->willReturnCallback(static function (string $project, Tier $tier) use (&$cooling): ResolvedModel {
            return Tier::Standard === $tier && $cooling ? throw new EveryAccountCoolingDown(Provider::OpenAiCompatible, 60) : new ResolvedModel(Provider::OpenAiCompatible, self::KIMI);
        });
        $progress = $this->progress($tiers);
        $progress->reached($this->waitingOnBoth());
        $cooling = true;

        $progress->reached(new RoundProgress(2, 2, false, ['reviewer' => FinishedState::Blocked, 'security-reviewer' => FinishedState::Verdict], 2, [], false));

        self::assertCount(1, $this->lines());
    }

    // Keys rejected or a disabled provider are no rate limit, so the demo leaves them to the reviewer, which ends with its own reason.
    public function testAnOutageWithoutARateLimitDoesNotStopTheDemo(): void
    {
        $this->progress($this->limited(new EveryProviderOut([ProviderOutage::keysRejected(Provider::OpenAiCompatible)])))->reached($this->waitingOnBoth());

        self::assertSame(['Waiting on the correctness reviewer and the security reviewer ('.self::DEEPSEEK.').'], $this->lines());
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-20T12:00:00+00:00');
        $this->output = new BufferedOutput();
    }

    private function answering(): TierInvoker
    {
        $tiers = self::createStub(TierInvoker::class);
        $tiers->method('answering')->willReturnCallback(static fn (string $project, Tier $tier): ResolvedModel => new ResolvedModel(Provider::OpenAiCompatible, SampleChange::PROJECT === $project && Tier::Standard === $tier ? self::DEEPSEEK : self::KIMI));

        return $tiers;
    }

    private function progress(TierInvoker $tiers): DemoProgress
    {
        return new DemoProgress($this->output, $tiers, $this->clock);
    }

    // Only the strong tier is out, so the stop names the reviewer whose tier is out.
    private function limited(Throwable $out): TierInvoker
    {
        $tiers = self::createStub(TierInvoker::class);
        $tiers->method('answering')->willReturnCallback(static fn (string $project, Tier $tier): ResolvedModel => Tier::Strong === $tier ? throw $out : new ResolvedModel(Provider::OpenAiCompatible, self::DEEPSEEK));

        return $tiers;
    }

    private function judgeLimited(Throwable $out): TierInvoker
    {
        $tiers = self::createStub(TierInvoker::class);
        $tiers->method('answering')->willReturnCallback(static fn (string $project, Tier $tier): ResolvedModel => Vendor::JUDGE_TIER === $tier ? throw $out : new ResolvedModel(Provider::OpenAiCompatible, self::DEEPSEEK));

        return $tiers;
    }

    private function waitingOnBoth(): RoundProgress
    {
        return new RoundProgress(0, 2, false, [], 0, [new AwaitedReviewer('correctness', Tier::Strong, 'reviewer'), new AwaitedReviewer('security', Tier::Standard, 'security-reviewer')]);
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        return array_values(array_filter(explode(PHP_EOL, $this->output->fetch()), static fn (string $line): bool => '' !== $line));
    }

    private function failureOf(callable $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('The call was expected to fail.');
    }
}
