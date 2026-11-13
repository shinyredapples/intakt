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
use Intakt\Core\Ledger\Contract\LedgerEventKind;
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

        $chosen = $this->use($ledger)->chooseFor($this->attemptNumber(1), BuildFacts::fromLabels([], ['README.md']));

        self::assertSame(Tier::Standard, $chosen->choice->tier);
        self::assertSame([LedgerEventKind::ModelChosen], $ledger->kinds());
        self::assertSame('default', $ledger->payloads()[0]['reason']);
    }

    public function testAModelLabelFromTheFirstCycleIsRecordedAsTheLabelsTier(): void
    {
        $ledger = new InMemoryLedger();

        (void) $this->use($ledger)->chooseFor($this->attemptNumber(1), BuildFacts::fromLabels(['model:light'], ['README.md']));

        self::assertSame('light', $ledger->payloads()[0]['tier']);
        self::assertSame('label', $ledger->payloads()[0]['reason']);
    }

    public function testASensitivePathIsRecordedOnStrongWhateverTheLabelSays(): void
    {
        $ledger = new InMemoryLedger();

        (void) $this->use($ledger)->chooseFor($this->attemptNumber(1), BuildFacts::fromLabels(['model:light'], ['src/Security/Login.php']));

        self::assertSame('strong', $ledger->payloads()[0]['tier']);
        self::assertSame('sensitive-path', $ledger->payloads()[0]['reason']);
    }

    /**
    #[DataProvider('setbacks')]
    public function testASetbackRaisesTheNextAttemptToStrong(Setback $setback): void
    {
        $ledger = new InMemoryLedger();

        (void) $this->use($ledger)->chooseFor($this->attemptNumber(2), BuildFacts::fromLabels([], ['README.md'], $setback));

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

        (void) $this->use($ledger)->chooseFor($this->attemptNumber(2), BuildFacts::fromLabels([], ['README.md'], null));

        self::assertSame('standard', $ledger->payloads()[0]['tier']);
    }

    public function testTheEventNamesTheProjectTaskAttemptProviderAndModel(): void
    {
        $ledger = new InMemoryLedger();

        (void) $this->use($ledger)->chooseFor($this->attemptNumber(3), BuildFacts::fromLabels([], []));

        self::assertSame(
            ['project' => 'alpha', 'task' => 'T60', 'attempt' => 3, 'tier' => 'standard', 'reason' => 'default', 'provider' => 'anthropic', 'model' => 'standard-model'],
            $ledger->payloads()[0],
        );
    }

    public function testALabelThatNamesAModelInsteadOfATierChoosesNothingByItself(): void
    {
        $ledger = new InMemoryLedger();

        (void) $this->use($ledger)->chooseFor($this->attemptNumber(1), BuildFacts::fromLabels(['model:claude-opus-5-5'], []));

        self::assertSame('default', $ledger->payloads()[0]['reason']);
    }

    public function testAnAttemptThatIsNotRecordedFailsTheChoice(): void
    {
        $this->expectException(ModelChoiceNotRecorded::class);
        $this->expectExceptionMessage('the ledger is down');

        (void) $this->use(new InMemoryLedger(0))->chooseFor($this->attemptNumber(1), BuildFacts::fromLabels([], []));
    }

    public function testAnAttemptNumberBelowOneIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BuildAttempt('alpha', 'T60', 0);
    }

    private function attemptNumber(int $number): BuildAttempt
    {
        return new BuildAttempt('alpha', 'T60', $number);
    }

    private function use(InMemoryLedger $ledger): ChooseBuildModel
    {
        $routing = new ConfiguredBuildRouting(['alpha' => ['tier' => Tier::Standard->value, 'sensitive_paths' => ['src/**/Security/**'], 'escalate_on' => ['failed-attempt', 'blocking-finding', 'stall']]], new ModelRouting());
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('resolve')->willReturnCallback(static fn (string $project, Tier $tier): ResolvedModel => new ResolvedModel(Provider::Anthropic, $tier->value.'-model'));

        return new ChooseBuildModel($routing, $tiers, $ledger);
    }
}
