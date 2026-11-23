<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Ai\Contract\ConfiguredKey;
use Intakt\Core\Ai\Contract\KeptProviderKeyUnreadable;
use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Ai\Infrastructure\ConfiguredAccounts;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\KeptProviderKeyFile;
use Intakt\Core\Dashboard\Domain\Character;
use Intakt\Core\Dashboard\Domain\FirstReviewStep;
use Intakt\Core\Dashboard\Domain\FirstRun;
use Intakt\Core\Dashboard\Domain\PanelOfProject;
use Intakt\Core\Dashboard\Domain\ProjectStep;
use Intakt\Core\Dashboard\Domain\ProviderStep;
use Intakt\Core\Dashboard\Domain\StepState;
use Intakt\Core\Dashboard\Domain\TierLine;
use Intakt\Core\Dashboard\Infrastructure\FormIsRefused;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundLock;
use Intakt\Core\Dashboard\Infrastructure\SampleChangeSource;
use Intakt\Core\Dashboard\UseCase\FirstRun\FirstRunController;
use Intakt\Core\Dashboard\UseCase\FirstRun\FirstRunSteps;
use Intakt\Core\Dashboard\UseCase\PlayDemo\DemoReadiness;
use Intakt\Core\Dashboard\UseCase\StartReview\ReviewStarts;
use Intakt\Core\Dashboard\UseCase\StartReview\StartAnswer;
use Intakt\Core\Dashboard\UseCase\StartReview\StartFirstReviewController;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\RoundNotNext;
use Intakt\Core\Review\Contract\SampleChange;
use Intakt\Core\Review\Contract\StartReview;
use Intakt\Core\Review\Infrastructure\ConfiguredPanels;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AnthropicDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\HuggingFaceDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\OpenAiDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiProviderCatalog;
use Intakt\Tests\Support\CompiledContainers;
use Intakt\Tests\Support\ReplayMarkBus;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LogLevel;
use stdClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[CoversClass(FirstRunController::class)]
#[CoversClass(FirstRunSteps::class)]
#[CoversClass(FirstRun::class)]
#[CoversClass(ProviderStep::class)]
#[CoversClass(ProjectStep::class)]
#[CoversClass(FirstReviewStep::class)]
#[CoversClass(PanelOfProject::class)]
#[CoversClass(TierLine::class)]
#[CoversClass(StepState::class)]
#[CoversClass(StartFirstReviewController::class)]
#[CoversClass(ReviewStarts::class)]
#[UsesClass(RoundLock::class)]
#[CoversClass(StartAnswer::class)]
#[CoversClass(SampleChangeSource::class)]
#[UsesClass(DemoReadiness::class)]
#[UsesClass(Character::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ConfiguredPanels::class)]
#[UsesClass(ConfiguredAccounts::class)]
#[UsesClass(KeptProviderKeyFile::class)]
#[UsesClass(ProviderKey::class)]
#[UsesClass(ConfiguredKey::class)]
#[UsesClass(SymfonyAiProviderCatalog::class)]
#[UsesClass(AnthropicDescriptor::class)]
#[UsesClass(OpenAiDescriptor::class)]
#[UsesClass(HuggingFaceDescriptor::class)]
#[UsesClass(KeptProviderKeyUnreadable::class)]
#[UsesClass(DashboardLedger::class)]
final class FirstRunControllerTest extends WebTestCase
{
    // The container is built from these, so each one a test changes is cleared first and restored after.
    private const array VARIABLES = [
        'ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'OPENAI_COMPATIBLE_BASE_URL', 'OPENAI_COMPATIBLE_API_KEY', 'CLAUDE_CODE_OAUTH_TOKEN', 'INTAKT_CONFIG',
        'MCP_OWNER_TOKEN', 'MCP_OWNER_ACCESS', 'INTAKT_COMPOSE_SERVICE', 'INTAKT_SAMPLE_REPOSITORY', 'INTAKT_PROVIDER_KEY_DIR',
        'INTAKT_DEMO_RECORDING',
    ];

    private const string SAMPLE_REPOSITORY = '/repos/sample';

    private const string PROJECTS = <<<'YAML'
        projects:
            sample:
                tiers:
                    strong: { provider: anthropic, model: big }
                    standard: { provider: anthropic, model: middle }
                    light: { provider: openai, model: small }
                review:
                    quorum: all
                    reviewers:
                        - { role: correctness, tier: strong, provider: anthropic, prompt: Check the logic., budget: 3 }
                        - { role: security, tier: standard, provider: anthropic, prompt: Check the risks., budget: 3 }
            beta:
                tiers:
                    strong: { provider: openai, model: other }
        YAML;

    // Built from parts at run time by the test, so no key-shaped literal sits in the repository.
    private const string KEPT_BODY = 'qrstuvwx12345678';

    private const string OWNER_TOKEN = 'owner-token-for-the-first-run-test';

    private string $file = '';

    private string $keptDirectory = '';

    /** @var array<string, array{server: mixed, env: mixed, process: string|false}> */
    private array $saved = [];

    private CompiledContainers $containers;

    private DashboardLedger $ledger;

    private ?MessageBusInterface $commands = null;

    private string $now = '2026-10-28T12:00:00+00:00';

    public static function tearDownAfterClass(): void
    {
        self::compiledContainers()->remove();
        parent::tearDownAfterClass();
    }

    public function testWithOneKeyTheProviderStepIsDoneAndNamesTheProviderThatHasIt(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('done', $page->filter('#step-providers')->attr('data-state'));
        self::assertSame('anthropic has a key.', $page->filter('#step-providers .lead')->text());
    }

    public function testWithOneKeyEveryOtherProviderIsNamedWithTheVariableToSet(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame([
            'Provider "openai" is disabled: set OPENAI_API_KEY to enable it.',
            'Provider "openai_compatible" is disabled: set OPENAI_COMPATIBLE_BASE_URL and OPENAI_COMPATIBLE_API_KEY to enable it.',
            'Provider "claude_code" is disabled: set CLAUDE_CODE_OAUTH_TOKEN to enable it.',
        ], $page->filter('#step-providers .keyless li')->each(static fn (Crawler $line): string => $line->text()));
    }

    public function testEachTierOfTheFirstProjectShowsItsModelOrWhyItHasNone(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('The tiers of the project sample:', $page->filter('#step-providers .tiers-of')->text());
        self::assertSame([
            'strong anthropic big',
            'standard anthropic middle',
            'light - Project "sample" maps the "light" tier to a disabled provider. Provider "openai" is disabled: set OPENAI_API_KEY to enable it.',
        ], $page->filter('#step-providers .tiers tbody tr')->each(static fn (Crawler $row): string => implode(' ', $row->filter('td')->each(static fn (Crawler $cell): string => $cell->text()))));
    }

    public function testWithNoKeyTheProviderStepIsMissingAndEveryProviderNamesItsVariable(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, []);

        self::assertSame('missing', $page->filter('#step-providers')->attr('data-state'));
        self::assertSame('No provider has a key yet: paste one below, or set the variable named below for a provider you use.', $page->filter('#step-providers .lead')->text());
        self::assertCount(4, $page->filter('#step-providers .keyless li'));
        self::assertStringContainsString('set ANTHROPIC_API_KEY to enable it.', $page->filter('#step-providers .keyless')->text());
    }

    public function testWithNoKeyTheProviderStepOffersOneKeyFieldWithTheFormToken(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, []);

        $form = $page->filter('#step-providers form.keep-provider-key');
        self::assertSame('/setup/provider-key', $form->attr('action'));
        self::assertNotSame('', $form->filter('input[name=_token]')->attr('value'));
        self::assertCount(1, $form->filter('input[type=password][name=key][autocomplete=off]'));
        self::assertNull($form->filter('input[name=key]')->attr('value'));
    }

    public function testTheKeyFieldIsLabelledWithTheCatalogsProviders(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, []);

        self::assertSame('A key or token for Anthropic, OpenAI or Hugging Face', $page->filter('#step-providers label[for=provider-key]')->text());
    }

    public function testWithAKeyedProviderTheProviderStepOffersNoKeyField(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertCount(0, $page->filter('#step-providers form'));
    }

    public function testAKeptKeyShowsItsProviderAndAtMostItsLastFourCharactersAndNoField(): void
    {
        $directory = $this->keptKeyDirectory();
        // Kept after the container is built, as the page keeps it in a running install, because a container built with the kept key turns its provider on.
        $this->firstRunPage(self::PROJECTS, [], $directory);
        new KeptProviderKeyFile($directory, new SymfonyAiProviderCatalog())->keep(ProviderKey::recognise('hf_'.self::KEPT_BODY, new SymfonyAiProviderCatalog()));

        $page = $this->browser()->request('GET', '/setup');

        self::assertSame('done', $page->filter('#step-providers')->attr('data-state'));
        self::assertSame('The huggingface key ending in '.substr(self::KEPT_BODY, -4).' is kept. Restart Intakt to use it.', $page->filter('#step-providers .lead')->text());
        self::assertCount(0, $page->filter('#step-providers form'));
        self::assertStringNotContainsString(self::KEPT_BODY, (string) $this->browser()->getResponse()->getContent());
    }

    public function testAKeptHuggingFaceTokenTurnsOnTheOpenAiCompatibleEndpointOnceTheContainerIsBuiltWithIt(): void
    {
        $directory = $this->keptKeyDirectory();
        new KeptProviderKeyFile($directory, new SymfonyAiProviderCatalog())->keep(ProviderKey::recognise('hf_'.self::KEPT_BODY, new SymfonyAiProviderCatalog()));

        $page = $this->firstRunPage(self::PROJECTS, [], $directory);

        self::assertSame('openai_compatible has a key.', $page->filter('#step-providers .lead')->text());
    }

    public function testAnUnreadableKeptKeyIsNamedInsteadOfOfferingTheField(): void
    {
        $directory = $this->keptKeyDirectory();
        new Filesystem()->dumpFile($directory.'/provider-key.json', 'not json');

        $page = $this->firstRunPage(self::PROJECTS, [], $directory);

        self::assertStringStartsWith('The key kept in the browser cannot be read', $page->filter('#step-providers .lead')->text());
        self::assertCount(0, $page->filter('#step-providers form'));
    }

    public function testWithNoKeyNoTierOfTheFirstProjectHasAModel(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, []);

        self::assertSame(['-', '-', '-'], $page->filter('#step-providers .tiers tbody td:nth-child(2)')->each(static fn (Crawler $cell): string => $cell->text()));
    }

    public function testTheProjectStepListsEachProjectWithTheReviewersOfItsPanel(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('done', $page->filter('#step-project')->attr('data-state'));
        self::assertSame('2 projects are configured.', $page->filter('#step-project .lead')->text());
        self::assertSame(['sample', 'beta'], $page->filter('#step-project .list .t')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSame(
            'Reviewers: correctness (strong tier on anthropic), security (standard tier on anthropic).',
            $page->filter('#step-project .list .d')->first()->text(),
        );
    }

    public function testAProjectWithoutAPanelSaysSoInsteadOfItsReviewers(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('Project "beta" has no review panel configured.', $page->filter('#step-project .list .d')->last()->text());
    }

    public function testWithNoProjectTheProjectStepIsMissingAndTheProviderStepListsNoTiers(): void
    {
        $page = $this->firstRunPage("projects: {}\n", ['ANTHROPIC_API_KEY']);

        self::assertSame('missing', $page->filter('#step-project')->attr('data-state'));
        self::assertSame('No project is configured yet: add one to the project file that INTAKT_CONFIG names.', $page->filter('#step-project .lead')->text());
        self::assertCount(0, $page->filter('#step-project .list'));
        self::assertCount(0, $page->filter('#step-providers .tiers'));
    }

    public function testThePageShowsNoAgentStepNoAddressAndNoCommandToCopy(): void
    {
        $_SERVER['MCP_OWNER_TOKEN'] = self::OWNER_TOKEN;
        $_SERVER['INTAKT_COMPOSE_SERVICE'] = 'intakt';

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);
        $html = (string) $this->browser()->getResponse()->getContent();

        self::assertCount(0, $page->filter('#step-agent, main pre, main code'));
        self::assertStringNotContainsString('/mcp/owner', $html);
        self::assertStringNotContainsString('claude mcp add', $html);
        self::assertStringNotContainsString(self::OWNER_TOKEN, $html);
    }

    public function testThePageShowsThreeStepCardsToAnOwnProject(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame(['1. Add a key here Done', '2. Add a project Done', '3. First review Missing'], $page->filter('.own-steps > li h3')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSame(['done', 'done', 'missing'], $page->filter('.own-steps > li')->each(static fn (Crawler $card): string => (string) $card->attr('data-state')));
    }

    public function testTheFirstStepThatIsNotDoneIsTheCurrentStep(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame(['step-first-review'], $page->filter('.own-steps > li[aria-current="step"]')->each(static fn (Crawler $card): string => (string) $card->attr('id')));
        self::assertSame(['step-first-review'], $page->filter('.own-steps > li.current')->each(static fn (Crawler $card): string => (string) $card->attr('id')));
    }

    public function testWithNoKeyTheProviderStepIsTheCurrentStep(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, []);

        self::assertSame(['step-providers'], $page->filter('.own-steps > li[aria-current="step"]')->each(static fn (Crawler $card): string => (string) $card->attr('id')));
    }

    public function testTheCurrentCardAloneShowsTheCharacterOfItsRoleInBothThemes(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, []);

        self::assertCount(2, $page->filter('.own-steps .step-role img.character'));
        self::assertCount(1, $page->filter('#step-providers .step-role img.character-light[src*="roles/maintainer-light-96"]'));
        self::assertCount(1, $page->filter('#step-providers .step-role img.character-dark[src*="roles/maintainer-dark-96"]'));
    }

    public function testTheFirstReviewCardWhenCurrentShowsTheCodeReviewer(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame(Character::CodeReviewer, self::getContainer()->get(FirstRunSteps::class)->firstRun()->currentCharacter());
        self::assertCount(2, $page->filter('#step-first-review .step-role img.character'));
        self::assertCount(2, $page->filter('.own-steps .step-role img.character'));
    }

    public function testWithNoCurrentCardNoRoleCharacterIsShown(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertNull(self::getContainer()->get(FirstRunSteps::class)->firstRun()->currentCharacter());
        self::assertCount(0, $page->filter('.own-steps .step-role'));
    }

    public function testTheCurrentStepIsChosenFromTheThreeShownStepsAndSkipsOneThatIsNotNeeded(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, []);
        $firstRun = self::getContainer()->get(FirstRunSteps::class)->firstRun();

        self::assertSame([StepState::Missing, StepState::Done, StepState::NotNeeded], $firstRun->states());
        self::assertSame(0, $firstRun->current());
        self::assertSame(['step-providers'], $page->filter('.own-steps > li.current')->each(static fn (Crawler $card): string => (string) $card->attr('id')));
    }

    public function testWhenTheShownStepsAreDoneOrNotNeededNoCardIsCurrentWithoutAnOwnerToken(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame(['done', 'done', 'not-needed'], $page->filter('.own-steps > li')->each(static fn (Crawler $card): string => (string) $card->attr('data-state')));
        self::assertNull(self::getContainer()->get(FirstRunSteps::class)->firstRun()->current());
        self::assertCount(0, $page->filter('.own-steps > li[aria-current]'));
    }

    public function testWhenEveryStepIsDoneNoCardIsCurrent(): void
    {
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertCount(0, $page->filter('.own-steps > li[aria-current]'));
        self::assertCount(0, $page->filter('.own-steps > li.current'));
    }

    public function testTheFirstReviewButtonSitsInTheStepButtonRow(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertCount(1, $page->filter('#step-first-review .step-nav .btn.primary'));
    }

    public function testTheReviewLinkSitsInTheStepButtonRow(): void
    {
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertCount(1, $page->filter('#step-first-review .step-nav a.btn.primary'));
    }

    public function testTheColumnSitsInTheContentAreaWithoutItsWidthCap(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertCount(1, $page->filter('main.content.wide > .setup'));
    }

    public function testWithTheSampleProjectAndNoReviewTheFirstReviewStepIsMissingAndOffersTheStart(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('missing', $page->filter('#step-first-review')->attr('data-state'));
        self::assertSame('No review has run yet: start the review of the change sample-change of the sample project.', $page->filter('#step-first-review .lead')->text());
        self::assertSame('/setup/first-review', $page->filter('#step-first-review form.start-first-review')->attr('action'));
        self::assertSame('post', $page->filter('#step-first-review form.start-first-review')->attr('method'));
    }

    public function testBeforeAReviewRanTheOnlyPrimaryButtonStartsTheFirstReview(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame(['Start the first review'], $page->filter('main .btn.primary')->each(static fn (Crawler $button): string => $button->text()));
        self::assertSame('submit', $page->filter('form.start-first-review .btn.primary')->attr('type'));
    }

    public function testAfterAReviewRanTheFirstReviewStepIsDoneAndLinksToItsReviewPage(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('done', $page->filter('#step-first-review')->attr('data-state'));
        self::assertSame('/reviews/round-1', $page->filter('#step-first-review a.btn.primary')->attr('href'));
        self::assertCount(0, $page->filter('form.start-first-review'));
    }

    public function testAfterAReviewRanTheOnlyPrimaryButtonOpensTheReview(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame(['Open the review'], $page->filter('main .btn.primary')->each(static fn (Crawler $button): string => $button->text()));
    }

    public function testTheSetupPageShowsTheMascotBesideItsTitleAsADecorativeImageInBothThemes(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);
        $images = $page->filter('.fr-hero img.character');

        self::assertSame(['', ''], $images->each(static fn (Crawler $image): ?string => $image->attr('alt')));
        self::assertSame(1, preg_match('~octopus-conducting-light[^"]*\.webp$~', $images->eq(0)->attr('src') ?? ''));
        self::assertSame(1, preg_match('~octopus-conducting-dark[^"]*\.webp$~', $images->eq(1)->attr('src') ?? ''));
    }

    public function testTheSetupPageShowsOneOctopusAt144Px(): void
    {
        $images = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY'])->filter('img[src*="octopus-"]');

        self::assertSame([['144', '144'], ['144', '144']], $images->each(static fn (Crawler $image): array => [$image->attr('width'), $image->attr('height')]));
    }

    public function testAfterAnApprovedFirstReviewTheSetupPageShowsTheBowingOctopus(): void
    {
        $this->ledger->startRoundOfQuorum(SampleChange::CHANGE, 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 0);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeRound('round-1');

        $sources = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY'])->filter('.fr-hero img.character')->each(static fn (Crawler $image): ?string => $image->attr('src'));

        self::assertSame(1, preg_match('~octopus-bowing-light~', $sources[0] ?? ''));
        self::assertSame(1, preg_match('~octopus-bowing-dark~', $sources[1] ?? ''));
    }

    public function testARunningFirstReviewIsShownInProgress(): void
    {
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('The first review, of the change sample-change of the alpha project, is in progress.', $page->filter('#step-first-review .lead')->text());
    }

    public function testAFirstReviewThatRanOutOfTimeSaysItEndedWithoutAJudgesCall(): void
    {
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);
        $this->now = '2026-10-30T12:00:00+00:00';

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('The first review, of the change sample-change of the alpha project, ended without a call of the judge.', $page->filter('#step-first-review .lead')->text());
    }

    public function testAJudgedFirstReviewNamesItsOutcome(): void
    {
        $this->ledger->startRoundOfQuorum(SampleChange::CHANGE, 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 0);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeRound('round-1');

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('The first review, of the change sample-change of the alpha project, is judged with the outcome approve.', $page->filter('#step-first-review .lead')->text());
    }

    public function testAJudgedFirstReviewWithoutARecordedQuorumSaysItsOutcomeWasNotRecorded(): void
    {
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('The first review, of the change sample-change of the alpha project, is judged; its outcome was not recorded.', $page->filter('#step-first-review .lead')->text());
    }

    public function testTheStepLinksToTheLatestRoundOfTheFirstReviewInTheLedger(): void
    {
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-2', 2);
        $this->ledger->startRoundOf('feat/other', 'round-3', 1);

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('/reviews/round-2', $page->filter('#step-first-review a.btn.primary')->attr('href'));
    }

    public function testWithoutASampleRepositoryAndNoReviewTheStepIsNotNeededAndThePageHasNoPrimaryButton(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('not-needed', $page->filter('#step-first-review')->attr('data-state'));
        self::assertSame(
            'No review has run yet, and this page has no sample change to start one from: your coding agent can ask for a review of a change of your own project.',
            $page->filter('#step-first-review .lead')->text(),
        );
        self::assertCount(0, $page->filter('main .btn.primary'));
        self::assertCount(0, $page->filter('form.start-first-review'));
    }

    public function testWithoutTheSampleProjectTheStepIsNotNeeded(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;

        $page = $this->firstRunPage(<<<'YAML'
            projects:
                beta:
                    tiers:
                        strong: { provider: anthropic, model: big }
            YAML, ['ANTHROPIC_API_KEY']);

        self::assertSame('not-needed', $page->filter('#step-first-review')->attr('data-state'));
        self::assertCount(0, $page->filter('form.start-first-review'));
    }

    public function testTheStartButtonStartsTheSampleChangeAndOpensItsLiveReviewPage(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $commands = $this->commandBus();
        $token = $this->tokenFromThePage();
        $commands->expects(self::once())->method('dispatch')
            ->with(self::equalTo(new StartReview(SampleChange::PROJECT, SampleChange::TASK, new RequestedRound(SampleChange::CHANGE, 1, new ChangeSource(self::SAMPLE_REPOSITORY, SampleChange::BASE)))))
            ->willReturn(new Envelope(new stdClass(), [new HandledStamp('round-1', 'handler')]));

        $this->browser()->request('POST', '/setup/first-review', ['_token' => $token]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/reviews/round-1');
    }

    public function testTheStartLeavesAToastNamingTheRoundsNumber(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $commands = $this->commandBus();
        $token = $this->tokenFromThePage();
        $commands->expects(self::once())->method('dispatch')->willReturn(new Envelope(new stdClass(), [new HandledStamp('round-1', 'handler')]));

        $this->browser()->request('POST', '/setup/first-review', ['_token' => $token]);

        $session = $this->browser()->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        self::assertSame(['Started round 1.'], $session->getFlashBag()->peek('toast'));
    }

    public function testAStartWhoseHandlerNamesNoRoundComesBackToTheFirstRunPage(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $commands = $this->commandBus();
        $token = $this->tokenFromThePage();
        $commands->expects(self::once())->method('dispatch')->willReturn(new Envelope(new stdClass()));

        $this->browser()->request('POST', '/setup/first-review', ['_token' => $token]);

        self::assertResponseRedirects('/setup');
        $this->browser()->followRedirect();
        self::assertSelectorTextSame('.toast', 'Started round 1.');
    }

    public function testASecondPressComesBackWithTheRefusalAndNoTrace(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $commands = $this->commandBus();
        $token = $this->tokenFromThePage();
        $commands->expects(self::once())->method('dispatch')
            ->willThrowException(RoundNotNext::for(1, 2));

        $this->browser()->request('POST', '/setup/first-review', ['_token' => $token]);

        self::assertResponseRedirects('/setup');
        $this->browser()->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Round 1 was asked for');
        self::assertStringNotContainsString('Stack trace', (string) $this->browser()->getResponse()->getContent());
    }

    public function testAStartWithoutTheTokenDispatchesNothing(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $this->commandBus()->expects(self::never())->method('dispatch');
        $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        $this->browser()->request('POST', '/setup/first-review', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(400);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The form token is missing or wrong, so nothing was started."', FormIsRefused::class));
    }

    public function testAStartOnAnInstallWithoutTheSampleRepositoryIsRefused(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $token = $this->tokenFromThePage();
        // Replaced in the running container, because the repository is read when the container is built, and a new build would lose the session that holds the token.
        $container = self::getContainer();
        $starts = new ReviewStarts($container->get(LedgerReviewPages::class), $container->get('command.bus'), new SampleChangeSource($container->get(TierResolver::class), null), $container->get(RoundLock::class));
        $container->set(ReviewStarts::class, $starts);

        $this->browser()->request('POST', '/setup/first-review', ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "This install has no sample change to review, so nothing was started."', NotFoundHttpException::class));
    }

    public function testThePageOpensWithTheRecordedReview(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;

        $page = $this->firstRunPage(self::PROJECTS, []);

        self::assertSame('See a real review', $page->filter('#see-review h1')->text());
        self::assertCount(1, $page->filter('main h1'));
    }

    public function testTheRecordedReviewComesBeforeTheSteps(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;

        $page = $this->firstRunPage(self::PROJECTS, []);

        self::assertSame(['see-review', 'step-providers'], array_slice($page->filter('.setup #see-review, .setup .own-steps > li')->each(static fn (Crawler $part): string => (string) $part->attr('id')), 0, 2));
    }

    public function testWithoutARecordingTheHeroSaysSoInPlainWordsAndOffersNoButton(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $_SERVER['INTAKT_COMPOSE_SERVICE'] = 'intakt';

        $page = $this->firstRunPage(self::PROJECTS, []);

        self::assertSame(DemoReadiness::NoRecording->firstRunLine(self::getContainer()->get(FirstRunSteps::class)->firstRun()), $page->filter('#see-review .next')->text());
        self::assertCount(0, $page->filter('form.play-demo'));
        self::assertCount(0, $page->filter('#see-review .fr-when'));
    }

    public function testWithoutARecordingOrAKeyTheKeyFormIsShownBelowTheHero(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $_SERVER['INTAKT_COMPOSE_SERVICE'] = 'intakt';

        $page = $this->firstRunPage(self::PROJECTS, []);

        self::assertCount(1, $page->filter('#step-providers form.keep-provider-key'));
        self::assertStringContainsString('Add a key below', $page->filter('#see-review .next')->text());
    }

    public function testWithoutARecordingAndWithAKeyTheHeroPointsToTheCardsAndNotToAKeyForm(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $_SERVER['INTAKT_COMPOSE_SERVICE'] = 'intakt';

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertCount(0, $page->filter('form.keep-provider-key'));
        self::assertStringNotContainsString('Add a key below', $page->filter('#see-review .next')->text());
        self::assertSame('This install has no recorded review. A live review can be started or opened from the cards below.', $page->filter('#see-review .next')->text());
        self::assertCount(1, $page->filter('#step-first-review form.start-first-review'));
    }

    public function testWithAKeyButNoSampleToStartTheHeroPromisesNoStartFromTheCards(): void
    {
        $_SERVER['INTAKT_COMPOSE_SERVICE'] = 'intakt';

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertCount(0, $page->filter('form.keep-provider-key, form.start-first-review, #step-first-review a.btn'));
        self::assertSame('This install has no recorded review, and no card below can start a live one yet.', $page->filter('#see-review .next')->text());
    }

    public function testThePageNamesNoCommandWhenTheSampleCannotStart(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, []);

        self::assertSame('not-needed', $page->filter('#step-first-review')->attr('data-state'));
        self::assertDoesNotMatchRegularExpression('/intakt:|bin\/console|docker /', $page->filter('main')->text());
    }

    public function testWithARecordingTheHeroHoldsThePlayTheDemoRunButtonAndSaysWhatHappens(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $_SERVER['INTAKT_COMPOSE_SERVICE'] = 'intakt';
        $_SERVER['INTAKT_DEMO_RECORDING'] = $this->file;

        $page = $this->firstRunPage(self::PROJECTS, []);

        self::assertSame('1 minute · no key', $page->filter('#see-review .fr-when')->text());
        self::assertSame('Two AI labs read a change, then the owl decides what to fix.', $page->filter('#see-review .next')->text());
        self::assertSame(['Play the demo run'], $page->filter('main .btn.primary')->each(static fn (Crawler $button): string => $button->text()));
        self::assertCount(1, $page->filter('#see-review form.play-demo'));
    }

    public function testWithARecordingTheFirstReviewStartIsASecondaryButton(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $_SERVER['INTAKT_COMPOSE_SERVICE'] = 'intakt';
        $_SERVER['INTAKT_DEMO_RECORDING'] = $this->file;

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('Start the first review', $page->filter('form.start-first-review .btn.secondary')->text());
    }

    public function testWithAReviewAndTheDemoReadyOpenTheReviewIsSecondaryAndPlayIsTheOnlyPrimaryButton(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $_SERVER['INTAKT_COMPOSE_SERVICE'] = 'intakt';
        $_SERVER['INTAKT_DEMO_RECORDING'] = $this->file;

        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('Open the review', $page->filter('a.btn.secondary')->text());
        self::assertSame(['Play the demo run'], $page->filter('main .btn.primary')->each(static fn (Crawler $button): string => $button->text()));
    }

    public function testTheTitleIsTheOnePrimaryMessage(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame(['See a real review'], $page->filter('main h1')->each(static fn (Crawler $title): string => $title->text()));
    }

    public function testThePageDrawsWhatTheReviewShowsWithACharacterAndAShortLabelEach(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame(['Read', 'Check', 'To fix'], $page->filter('.fr-seq li strong')->each(static fn (Crawler $label): string => $label->text()));
        self::assertSame([2, 2, 2], $page->filter('.fr-seq li')->each(static fn (Crawler $step): int => $step->filter('img.character[alt=""]')->count()));
        self::assertCount(2, $page->filter('.fr-seq li')->eq(1)->filter('img[src*="judge-"]'));
        self::assertCount(2, $page->filter('.fr-seq li')->eq(2)->filter('img[src*="roles/developer-"][srcset]'));
    }

    public function testThePageHasNoDisclosure(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertCount(0, $page->filter('main details'));
        self::assertCount(1, $page->filter('#step-providers .tiers'));
    }

    public function testAProjectNameWithMarkupIsEscaped(): void
    {
        $page = $this->firstRunPage(<<<'YAML'
            projects:
                '<b>bold</b>':
                    tiers:
                        strong: { provider: anthropic, model: big }
            YAML, ['ANTHROPIC_API_KEY']);

        self::assertCount(0, $page->filter('main b'));
        self::assertSame('<b>bold</b>', $page->filter('#step-project .list .t')->text());
    }

    protected function setUp(): void
    {
        $this->containers = self::compiledContainers();

        foreach (self::VARIABLES as $name) {
            $this->saved[$name] = ['server' => $_SERVER[$name] ?? null, 'env' => $_ENV[$name] ?? null, 'process' => getenv($name)];
            $this->unsetVariable($name);
        }

        $this->file = sys_get_temp_dir().'/intakt-first-run-'.bin2hex(random_bytes(4)).'.yaml';
        $_SERVER['INTAKT_CONFIG'] = $this->file;
        $_SERVER['MCP_OWNER_ACCESS'] = 'mcp_tool_write';
        $_SERVER['INTAKT_COMPOSE_SERVICE'] = '';
        $_SERVER['MCP_OWNER_TOKEN'] = '';
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = '';
        $_SERVER['INTAKT_DEMO_RECORDING'] = '';
        $this->ledger = new DashboardLedger();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $before) {
            $this->unsetVariable($name);
            if (null !== $before['server']) {
                $_SERVER[$name] = $before['server'];
            }
            if (null !== $before['env']) {
                $_ENV[$name] = $before['env'];
            }
            if (false !== $before['process']) {
                putenv($name.'='.$before['process']);
            }
        }

        parent::tearDown();
        $this->containers->restoreTheSharedCache();
        new Filesystem()->remove([$this->file, $this->keptDirectory]);
    }

    /**
     * @param list<string> $keys
     */
    private function firstRunPage(string $projects, array $keys, string $keptKeyDirectory = ''): Crawler
    {
        $_SERVER['INTAKT_PROVIDER_KEY_DIR'] = '' === $keptKeyDirectory ? sys_get_temp_dir().'/intakt-no-kept-key' : $keptKeyDirectory;
        file_put_contents($this->file, $projects);

        foreach ($keys as $key) {
            $_SERVER[$key] = 'test-value';
        }

        self::ensureKernelShutdown();
        // Keyed by what the container is built from, so an install with other keys or projects gets a container of its own.
        $this->containers->useFor([$projects, $keys, $keptKeyDirectory, $_SERVER['INTAKT_SAMPLE_REPOSITORY'] ?? '']);
        $browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        // Kept between requests, so the ledger double and the bus mock stay for the post after the page that hands out the token.
        $browser->disableReboot();
        self::getContainer()->set(Ledger::class, $this->ledger);
        // The ledger stamps its rows at one moment, so a round is not overdue and ended by the time the page reads it.
        self::clockAt($this->now);
        // Answered without the database, because the first review step reads the ledger in memory and this test creates no schema.
        self::getContainer()->set(ReplayMarks::class, new ReplayMarks(new ReplayMarkBus()));
        if ($this->commands instanceof MessageBusInterface) {
            self::getContainer()->set('command.bus', $this->commands);
        }
        $page = $browser->request('GET', '/setup');
        self::assertResponseIsSuccessful();

        return $page;
    }

    // Set by the next page, because the page's own services take the bus when they are built.
    private function commandBus(): MessageBusInterface&MockObject
    {
        $commands = $this->createMock(MessageBusInterface::class);
        $this->commands = $commands;

        return $commands;
    }

    // Read from the page's form, because the token belongs to the browser's session.
    private function tokenFromThePage(): string
    {
        return $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY'])->filter('form.start-first-review input[name=_token]')->attr('value') ?? '';
    }

    private static function compiledContainers(): CompiledContainers
    {
        return CompiledContainers::beside(self::createKernel()->getCacheDir(), 'first-run');
    }

    private function keptKeyDirectory(): string
    {
        $this->keptDirectory = sys_get_temp_dir().'/'.uniqid('first-run-kept-', true);

        return $this->keptDirectory;
    }

    private function browser(): KernelBrowser
    {
        $browser = self::getClient();
        self::assertInstanceOf(KernelBrowser::class, $browser);

        return $browser;
    }

    private function unsetVariable(string $name): void
    {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);
    }
}
