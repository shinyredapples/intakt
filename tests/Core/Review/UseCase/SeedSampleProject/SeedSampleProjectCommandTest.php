<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\SeedSampleProject;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\EnabledAccounts;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\ModelPrice;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\SampleProjectData;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleProject;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleRepository;
use Intakt\Core\Review\UseCase\SeedSampleProject\SeedSampleProjectCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(SeedSampleProjectCommand::class)]
#[CoversClass(SampleProject::class)]
#[CoversClass(SampleRepository::class)]
#[UsesClass(Account::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Tier::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(Panel::class)]
#[UsesClass(PanelReviewer::class)]
#[UsesClass(PanelVerdict::class)]
#[UsesClass(QuorumPolicy::class)]
#[UsesClass(ReviewerModel::class)]
#[UsesClass(ReviewerRole::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Decision::class)]
#[UsesClass(SampleProjectData::class)]
#[UsesClass(ModelPrice::class)]
final class SeedSampleProjectCommandTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../../../fixtures/sample-repository';

    private string $directory;

    private string $compatibleBaseUrl;

    private SampleProjectData $data;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-sample-seed-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->directory);
        $this->data = new SampleProjectData();
        $this->compatibleBaseUrl = $this->data->routerUrl()->toRawString();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    /**
     * @return iterable<string, array{list<Provider>}>
     */
    public static function keyCombinations(): iterable
    {
        yield 'anthropic' => [[Provider::Anthropic]];
        yield 'openai' => [[Provider::OpenAi]];
        yield 'the hugging face router' => [[Provider::OpenAiCompatible]];
        yield 'anthropic and the router' => [[Provider::Anthropic, Provider::OpenAiCompatible]];
        yield 'anthropic and openai' => [[Provider::Anthropic, Provider::OpenAi]];
        yield 'openai and the router' => [[Provider::OpenAi, Provider::OpenAiCompatible]];
        yield 'anthropic, openai and the router' => [[Provider::Anthropic, Provider::OpenAi, Provider::OpenAiCompatible]];
    }

    /**
     * @param list<Provider> $keyed
     */
    #[DataProvider('keyCombinations')]
    public function testTheSeededFilePricesEveryModelItsTiersNameAndNoOther(array $keyed): void
    {
        $this->seed(...$keyed);

        $named = array_values(array_unique(array_column($this->writtenProject()['tiers'], 'model')));
        $priced = array_keys($this->writtenPrices());
        sort($named);
        sort($priced);
        self::assertSame($named, $priced);
        foreach ($this->writtenPrices() as $price) {
            self::assertGreaterThan(0, $price['input']);
            self::assertGreaterThan(0, $price['output']);
        }
    }

    public function testWithOnlyTheRouterTheSecondAiLabsModelIsPriced(): void
    {
        $this->seed(Provider::OpenAiCompatible);

        self::assertEquals([
            'deepseek-ai/DeepSeek-V4-Pro-0813:deepinfra' => ['input' => 1.3, 'output' => 2.6],
            'moonshotai/Kimi-K3.1-Pro:deepinfra' => ['input' => 1.9, 'output' => 9.5],
        ], $this->writtenPrices());
        self::assertSame('2026-11-18', $this->written()['budget']['price_date']);
    }

    public function testOneKeyPutsTheWholePanelOnThatProvider(): void
    {
        $status = $this->seed(Provider::Anthropic)->getStatusCode();

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(['anthropic'], $this->panelProviders());
    }

    public function testAnOpenAiKeyAlonePutsThePanelOnOpenAi(): void
    {
        $this->seed(Provider::OpenAi);

        self::assertSame(['openai'], $this->panelProviders());
    }

    public function testTwoKeysPutTheTwoReviewersOnTwoProviders(): void
    {
        $display = $this->seed(Provider::Anthropic, Provider::OpenAi)->getDisplay();

        self::assertSame(['anthropic', 'openai'], $this->panelProviders());
        self::assertStringContainsString('its panel on anthropic and openai', $display);
    }

    public function testAHuggingFaceTokenAlonePutsTheTwoReviewersOnModelsOfTwoAiLabs(): void
    {
        $display = $this->seed(Provider::OpenAiCompatible)->getDisplay();

        self::assertSame(['openai_compatible'], $this->panelProviders());
        self::assertSame(['moonshotai/Kimi-K3.1-Pro:deepinfra', 'deepseek-ai/DeepSeek-V4-Pro-0813:deepinfra'], $this->reviewerModels());
        self::assertStringContainsString('its panel on openai_compatible', $display);
    }

    public function testWithAnAnthropicKeyTheSecurityReviewerMovesToHuggingFace(): void
    {
        $this->seed(Provider::Anthropic, Provider::OpenAiCompatible);

        self::assertSame(['anthropic', 'openai_compatible'], $this->panelProviders());
        self::assertSame(['claude-opus-5-5', 'moonshotai/Kimi-K3.1-Pro:deepinfra'], $this->reviewerModels());
    }

    public function testAnOpenAiCompatibleEndpointOtherThanTheHuggingFaceRouterSeedsNothing(): void
    {
        $this->compatibleBaseUrl = 'https://llm.example.test';

        $this->seed(Provider::OpenAiCompatible);

        self::assertFileDoesNotExist($this->configuration());
    }

    public function testAnOpenAiCompatibleEndpointBesideAnotherKeyStaysOffThePanel(): void
    {
        $this->compatibleBaseUrl = 'https://llm.example.test';

        $this->seed(Provider::Anthropic, Provider::OpenAiCompatible);

        self::assertSame(['anthropic'], $this->panelProviders());
    }

    public function testTheSampleProjectSeedsItsModelsAndPricesFromTheDataFile(): void
    {
        $this->givenADataFile();

        $this->seed(Provider::Anthropic);

        self::assertSame(['strong' => 'made-up-strong', 'standard' => 'made-up-standard', 'light' => 'made-up-strong'], array_map(static fn (array $tier): string => $tier['model'], $this->writtenProject()['tiers']));
        self::assertSame(['made-up-strong' => ['input' => 7, 'output' => 70], 'made-up-standard' => ['input' => 0.7, 'output' => 7]], $this->writtenPrices());
    }

    public function testTheSeededPriceDateComesFromTheDataFile(): void
    {
        $this->givenADataFile();

        $this->seed(Provider::Anthropic);

        self::assertSame('2030-01-02', $this->written()['budget']['price_date']);
        self::assertStringContainsString('The price table is dated 2030-01-02', new Filesystem()->readFile($this->configuration()));
    }

    public function testWithOnlyTheRouterTheSecondAiLabIsTheOneTheDataFileNames(): void
    {
        $this->givenADataFile();
        $this->compatibleBaseUrl = 'https://router.example.test';

        $this->seed(Provider::OpenAiCompatible);

        self::assertSame(['made-up-router', 'made-up-second-lab'], $this->reviewerModels());
    }

    public function testTheOpenAiCompatibleEndpointCountsAtTheRouterUrlOfTheDataFile(): void
    {
        $this->givenADataFile();
        $this->compatibleBaseUrl = 'https://router.example.test';

        $this->seed(Provider::Anthropic, Provider::OpenAiCompatible);

        self::assertSame(['anthropic', 'openai_compatible'], $this->panelProviders());
    }

    public function testTheShippedRouterUrlDoesNotCountWhenTheDataFileNamesAnotherRouter(): void
    {
        $this->givenADataFile();

        $this->seed(Provider::Anthropic, Provider::OpenAiCompatible);

        self::assertSame(['anthropic'], $this->panelProviders());
    }

    public function testEachReviewerRunsOnTheProviderItsTierMapsTo(): void
    {
        $this->seed(Provider::Anthropic, Provider::OpenAi);

        $project = $this->writtenProject();

        foreach ($project['review']['reviewers'] as $reviewer) {
            self::assertSame($project['tiers'][$reviewer['tier']]['provider'], $reviewer['provider']);
        }
    }

    public function testWithoutAKeyTheProjectIsLeftUnwrittenAndTheKeysAreNamed(): void
    {
        $tester = $this->seed();

        self::assertStringContainsString('Set ANTHROPIC_API_KEY or OPENAI_API_KEY or HF_TOKEN', $tester->getDisplay());
        self::assertFileDoesNotExist($this->configuration());
    }

    public function testWithoutAKeyTheRepositoryIsStillBuiltAndTheSeedSucceeds(): void
    {
        $tester = $this->seed();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertDirectoryExists($this->repository().'/.git');
    }

    public function testAProjectLeftUnwrittenForWantOfAKeyIsWrittenOnceAKeyIsSet(): void
    {
        $this->seed();

        $this->seed(Provider::Anthropic);

        self::assertSame(['anthropic'], $this->panelProviders());
    }

    public function testTheRepositoryHoldsTheSampleChangeAgainstMain(): void
    {
        $this->seed(Provider::Anthropic);

        $changed = $this->git('diff', '--name-only', SampleRepository::BASE.'...'.SampleRepository::BRANCH);

        self::assertSame("src/discount.js\nsrc/report.js\nsrc/shipping.js", trim($changed));
        self::assertSame(SampleRepository::BASE, trim($this->git('branch', '--show-current')));
    }

    public function testEveryInstallBuildsTheSameSampleCommits(): void
    {
        $this->seed(Provider::Anthropic);
        $first = $this->git('rev-parse', SampleRepository::BRANCH);
        new Filesystem()->remove($this->repository());

        $this->seed(Provider::Anthropic);

        self::assertSame($first, $this->git('rev-parse', SampleRepository::BRANCH));
    }

    public function testASecondStartKeepsTheRepositoryAndTheProjectAsTheyAre(): void
    {
        $this->seed(Provider::Anthropic);
        $head = $this->git('rev-parse', SampleRepository::BRANCH);
        $written = new Filesystem()->readFile($this->configuration());

        $display = $this->seed(Provider::Anthropic, Provider::OpenAi)->getDisplay();

        self::assertSame($written, new Filesystem()->readFile($this->configuration()));
        self::assertSame($head, $this->git('rev-parse', SampleRepository::BRANCH));
        self::assertStringContainsString('Kept the project configuration', $display);
        self::assertStringContainsString('Kept the sample repository', $display);
    }

    public function testAKeptProjectNeedsNoKeyToBuildAMissingRepository(): void
    {
        new Filesystem()->dumpFile($this->configuration(), "projects: {}\n");

        $status = $this->seed()->getStatusCode();

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame("projects: {}\n", new Filesystem()->readFile($this->configuration()));
        self::assertDirectoryExists($this->repository().'/.git');
    }

    public function testAnExistingRepositoryDirectoryIsLeftUntouched(): void
    {
        new Filesystem()->dumpFile($this->repository().'/mine.txt', 'mine');

        $this->seed(Provider::Anthropic);

        self::assertSame(['.', '..', 'mine.txt'], scandir($this->repository()));
    }

    public function testAFailedBuildLeavesNoRepositoryForALaterStartToKeep(): void
    {
        $command = new SeedSampleProjectCommand(new SampleRepository($this->directory.'/no-fixtures'), $this->project(Provider::Anthropic), new LockFactory(new InMemoryStore()));

        $failure = null;
        try {
            $this->tester($command)->execute(['repository' => $this->repository(), 'configuration' => $this->configuration()]);
        } catch (IOException $failure) {
        }

        self::assertInstanceOf(IOException::class, $failure);
        self::assertSame(['.', '..', 'intakt.yaml'], scandir($this->directory));
    }

    public function testTheSeedLeavesTheReviewCommandToTheStartOnceTheServerAnswers(): void
    {
        $display = $this->seed(Provider::Anthropic)->getDisplay();

        self::assertStringNotContainsString('intakt:review', $display);
        self::assertStringNotContainsString('mcp/owner', $display);
    }

    private function seed(Provider ...$keyed): CommandTester
    {
        $command = new SeedSampleProjectCommand(new SampleRepository(self::FIXTURES), $this->project(...$keyed), new LockFactory(new InMemoryStore()));
        $tester = $this->tester($command);
        $tester->execute(['repository' => $this->repository(), 'configuration' => $this->configuration()]);

        return $tester;
    }

    private function tester(SeedSampleProjectCommand $command): CommandTester
    {
        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($application->find('intakt:sample:seed'));
    }

    private function project(Provider ...$keyed): SampleProject
    {
        $accounts = self::createStub(EnabledAccounts::class);
        $accounts->method('of')->willReturnCallback(static fn (Provider $provider): array => in_array($provider, $keyed, true) ? [new Account($provider, Account::DEFAULT)] : []);

        return new SampleProject($accounts, $this->compatibleBaseUrl, $this->data);
    }

    // Made-up names, prices and date, so a value that still came from code instead of the data file would show.
    private function givenADataFile(): void
    {
        $file = $this->directory.'/sample-project.yaml';
        new Filesystem()->dumpFile($file, Yaml::dump([
            'router_url' => 'https://router.example.test',
            'price_date' => '2030-01-02',
            'models' => [
                'anthropic' => ['strong' => 'made-up-strong', 'standard' => 'made-up-standard', 'light' => 'made-up-strong'],
                'openai' => ['strong' => 'made-up-strong', 'standard' => 'made-up-standard', 'light' => 'made-up-strong'],
                'openai_compatible' => ['strong' => 'made-up-router', 'standard' => 'made-up-router', 'light' => 'made-up-router'],
            ],
            'second_lab' => 'made-up-second-lab',
            'prices' => [
                'made-up-strong' => ['input' => 7, 'output' => 70],
                'made-up-standard' => ['input' => 0.7, 'output' => 7],
                'made-up-router' => ['input' => 1, 'output' => 2],
                'made-up-second-lab' => ['input' => 3, 'output' => 4],
            ],
        ]));
        $this->data = new SampleProjectData($file);
    }

    /**
     * @return list<string>
     */
    private function panelProviders(): array
    {
        return array_values(array_unique(array_column($this->writtenProject()['review']['reviewers'], 'provider')));
    }

    /**
     * @return list<string>
     */
    private function reviewerModels(): array
    {
        $project = $this->writtenProject();

        return array_map(static fn (array $reviewer): string => $project['tiers'][$reviewer['tier']]['model'], $project['review']['reviewers']);
    }

    /**
     * @return array{tiers: array<string, array{provider: string, model: string}>, review: array{quorum: string, reviewers: list<array{role: string, tier: string, provider: string}>}}
     */
    private function writtenProject(): array
    {
        return $this->written()['projects']['sample'];
    }

    /**
     * @return array<string, array{input: int|float, output: int|float}>
     */
    private function writtenPrices(): array
    {
        return $this->written()['budget']['prices'];
    }

    /**
     * @return array{projects: array{sample: array{tiers: array<string, array{provider: string, model: string}>, review: array{quorum: string, reviewers: list<array{role: string, tier: string, provider: string}>}}}, budget: array{price_date: string, prices: array<string, array{input: int|float, output: int|float}>}}
     */
    private function written(): array
    {
        /** @var array{projects: array{sample: array{tiers: array<string, array{provider: string, model: string}>, review: array{quorum: string, reviewers: list<array{role: string, tier: string, provider: string}>}}}, budget: array{price_date: string, prices: array<string, array{input: int|float, output: int|float}>}} $written */
        $written = Yaml::parseFile($this->configuration());

        return $written;
    }

    private function git(string ...$arguments): string
    {
        $call = new HardenedGit()->call($this->repository(), ...$arguments);

        return new Process([...$call->command], $call->directory, [...$call->environment])->mustRun()->getOutput();
    }

    private function repository(): string
    {
        return $this->directory.'/sample-repository';
    }

    private function configuration(): string
    {
        return $this->directory.'/intakt.yaml';
    }
}
