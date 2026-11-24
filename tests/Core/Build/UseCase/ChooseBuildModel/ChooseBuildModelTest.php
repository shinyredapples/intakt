<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\ChooseBuildModel;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\Setback;
use Intakt\Core\Build\Contract\TierChoice;
use Intakt\Core\Build\Domain\ModelRouting;
use Intakt\Core\Build\Domain\RoutingRules;
use Intakt\Core\Build\Infrastructure\ConfiguredBuildRouting;
use Intakt\Core\Build\UseCase\ChooseBuildModel\BuildAttempt;
use Intakt\Core\Build\UseCase\ChooseBuildModel\ChooseBuildModel;
use Intakt\Core\Build\UseCase\ChooseBuildModel\ChosenModel;
use Intakt\Core\Build\UseCase\ChooseBuildModel\ModelChoiceNotRecorded;
use Intakt\Core\Build\UseCase\RunBuildJob\BuilderNotOnClaudeCode;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\PathGlob;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChooseBuildModel::class)]
#[CoversClass(BuildAttempt::class)]
#[CoversClass(ModelChoiceNotRecorded::class)]
#[UsesClass(ChosenModel::class)]
#[UsesClass(BuilderNotOnClaudeCode::class)]
#[UsesClass(ConfiguredBuildRouting::class)]
#[UsesClass(ModelRouting::class)]
#[UsesClass(RoutingRules::class)]
#[UsesClass(BuildFacts::class)]
#[UsesClass(TierChoice::class)]
#[UsesClass(PathGlob::class)]
final class ChooseBuildModelTest extends TestCase
{
    public function testAnOrdinaryBuildIsRecordedOnTheDefaultTier(): void
    {
        $ledger = new InMemoryLedger();

        $chosen = $this->use($ledger)->chooseFor($this->attemptNumber(1), BuildFacts::fromLabels(new Labels(), new Paths('README.md')));

        self::assertSame(Tier::Standard, $chosen->choice->tier);
        self::assertSame([LedgerEventKind::ModelChosen], $ledger->kinds());
        self::assertSame('default', $ledger->payloads()[0]['reason']);
    }

    public function testAModelLabelFromTheFirstCycleIsRecordedAsTheLabelsTier(): void
    {
        $ledger = new InMemoryLedger();

        (void) $this->use($ledger)->chooseFor($this->attemptNumber(1), BuildFacts::fromLabels(new Labels('model:light'), new Paths('README.md')));

        self::assertSame('light', $ledger->payloads()[0]['tier']);
        self::assertSame('label', $ledger->payloads()[0]['reason']);
    }

    public function testASensitivePathIsRecordedOnStrongWhateverTheLabelSays(): void
    {
        $ledger = new InMemoryLedger();

        (void) $this->use($ledger)->chooseFor($this->attemptNumber(1), BuildFacts::fromLabels(new Labels('model:light'), new Paths('src/Security/Login.php')));

        self::assertSame('strong', $ledger->payloads()[0]['tier']);
        self::assertSame('sensitive-path', $ledger->payloads()[0]['reason']);
    }

    #[DataProvider('setbacks')]
    public function testASetbackRaisesTheNextAttemptToStrong(Setback $setback): void
    {
        $ledger = new InMemoryLedger();

        (void) $this->use($ledger)->chooseFor($this->attemptNumber(2), BuildFacts::fromLabels(new Labels(), new Paths('README.md'), $setback));

        self::assertSame('strong', $ledger->payloads()[0]['tier']);
        self::assertSame('setback', $ledger->payloads()[0]['reason']);
    }

    /**
     * @return iterable<string, array{Setback}>
     */
    public static function setbacks(): iterable
    {
        yield 'an earlier failed attempt' => [Setback::FailedAttempt];
        yield 'a blocking finding' => [Setback::BlockingFinding];
        yield 'a stall' => [Setback::Stall];
    }

    public function testAReworkAfterARoundWithoutAP1StaysOnTheCheaperTier(): void
    {
        $ledger = new InMemoryLedger();

        (void) $this->use($ledger)->chooseFor($this->attemptNumber(2), BuildFacts::fromLabels(new Labels(), new Paths('README.md')));

        self::assertSame('standard', $ledger->payloads()[0]['tier']);
    }

    public function testTheEventNamesTheProjectTaskAttemptProviderAndModel(): void
    {
        $ledger = new InMemoryLedger();

        (void) $this->use($ledger)->chooseFor($this->attemptNumber(3), BuildFacts::fromLabels(new Labels(), new Paths()));

        self::assertSame(
            ['project' => 'alpha', 'task' => 'T60', 'attempt' => 3, 'tier' => 'standard', 'reason' => 'default', 'provider' => 'claude_code', 'model' => 'standard-model'],
            $ledger->payloads()[0],
        );
    }

    public function testALabelThatNamesAModelInsteadOfATierChoosesNothingByItself(): void
    {
        $ledger = new InMemoryLedger();

        (void) $this->use($ledger)->chooseFor($this->attemptNumber(1), BuildFacts::fromLabels(new Labels('model:claude-opus-5-5'), new Paths()));

        self::assertSame('default', $ledger->payloads()[0]['reason']);
    }

    public function testAnAttemptThatIsNotRecordedFailsTheChoice(): void
    {
        $this->expectException(ModelChoiceNotRecorded::class);

        (void) $this->use(new InMemoryLedger(0))->chooseFor($this->attemptNumber(1), BuildFacts::fromLabels(new Labels(), new Paths()));
    }

    public function testATierOnAProviderABuildCannotRunOnIsRefusedWithoutRecording(): void
    {
        $ledger = new InMemoryLedger();
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('resolve')->willReturn(new ResolvedModel(Provider::Anthropic, 'standard-model'));

        try {
            (void) $this->use($ledger, $tiers)->chooseFor($this->attemptNumber(1), BuildFacts::fromLabels(new Labels(), new Paths()));
            self::fail('A provider other than Claude Code was accepted.');
        } catch (BuilderNotOnClaudeCode $refusal) {
            self::assertEquals(new BuilderNotOnClaudeCode('alpha', Tier::Standard, Provider::Anthropic), $refusal);
        }

        self::assertSame(0, $ledger->count());
    }

    public function testAnAttemptNumberBelowOneIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BuildAttempt('alpha', 'T60', 0);
    }

    #[DataProvider('refusedAttempts')]
    public function testAnEmptyOrNonUtf8ProjectOrTaskIsRefused(string $project, string $task, string $message): void
    {
        $this->expectExceptionObject(new InvalidArgumentException($message));

        new BuildAttempt($project, $task, 1);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedAttempts(): iterable
    {
        yield 'an empty project' => ['', 'T60', 'A project must not be empty.'];
        yield 'an empty task' => ['alpha', '', 'A task must not be empty.'];
        yield 'a project that is not UTF-8' => ["alpha\xFF", 'T60', 'A project must be valid UTF-8.'];
        yield 'a task that is not UTF-8' => ['alpha', "T\xC360", 'A task must be valid UTF-8.'];
    }

    public function testASingleCharacterProjectAndTaskAreAccepted(): void
    {
        $attempt = new BuildAttempt('a', 'é', 1);

        self::assertSame(['a', 'é'], [$attempt->project, $attempt->task]);
    }

    private function use(InMemoryLedger $ledger, ?TierResolver $tiers = null): ChooseBuildModel
    {
        $routing = new ConfiguredBuildRouting(['alpha' => ['tier' => Tier::Standard->value, 'sensitive_paths' => ['src/**/Security/**'], 'escalate_on' => ['failed-attempt', 'blocking-finding', 'stall']]], new ModelRouting());

        return new ChooseBuildModel($routing, $tiers ?? $this->claudeCodeTiers(), $ledger);
    }

    private function attemptNumber(int $number): BuildAttempt
    {
        return new BuildAttempt('alpha', 'T60', $number);
    }

    private function claudeCodeTiers(): TierResolver
    {
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('resolve')->willReturnCallback(static fn (string $project, Tier $tier): ResolvedModel => new ResolvedModel(Provider::ClaudeCode, $tier->value.'-model'));

        return $tiers;
    }
}
