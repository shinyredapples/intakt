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
use Intakt\Core\Dashboard\Domain\RepositoryField;
use Intakt\Core\Dashboard\Domain\SetupStep;
use Intakt\Core\Dashboard\Domain\StepState;
use Intakt\Core\Dashboard\Domain\TierLine;
use Intakt\Core\Dashboard\Infrastructure\FormIsRefused;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundLock;
use Intakt\Core\Dashboard\Infrastructure\SampleChangeSource;
use Intakt\Core\Dashboard\UseCase\PlayDemo\DemoReadiness;
use Intakt\Core\Dashboard\UseCase\RunSetup\RunSetupController;
use Intakt\Core\Dashboard\UseCase\RunSetup\SetupSteps;
use Intakt\Core\Dashboard\UseCase\StartReview\ReviewStarts;
use Intakt\Core\Dashboard\UseCase\StartReview\StartAnswer;
use Intakt\Core\Dashboard\UseCase\StartReview\StartFirstReviewController;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Platform\Infrastructure\HeldCredentialRedactor;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\RoundNotNext;
use Intakt\Core\Review\Contract\SampleChange;
use Intakt\Core\Review\Contract\StartReview;
use Intakt\Core\Review\Infrastructure\ConfiguredPanels;
use Intakt\Core\Watcher\Contract\FailingMain;
use Intakt\Core\Watcher\Contract\FailingMains;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AnthropicDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\HuggingFaceDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\OpenAiDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiProviderCatalog;
use Intakt\Tests\Support\CompiledContainers;
use Intakt\Tests\Support\ReplayMarkBus;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

#[CoversClass(RunSetupController::class)]
#[CoversClass(SetupSteps::class)]
#[CoversClass(SetupStep::class)]
#[CoversClass(RepositoryField::class)]
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
#[UsesClass(FailingMains::class)]
#[UsesClass(FailingMain::class)]
#[UsesClass(HeldCredentialRedactor::class)]
final class RunSetupControllerTest extends WebTestCase
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

    private const string OWNER_TOKEN = 'owner-token-for-the-setup-test';

    private string $file = '';

    private string $keptDirectory = '';

    private string $path = '/setup';

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

    public function testWithoutAStepTheWizardOpensOnStepOneOfFive(): void
    {
        $page = $this->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('Step 1 of 5: Repository', $page->filter('.wizard .step-count')->text());
        self::assertSame(['Connect your repository'], $page->filter('main h1')->each(static fn (Crawler $title): string => $title->text()));
    }

    public function testOnStepThreeTheEarlierStepsAreDoneAndTheCountNamesTheStep(): void
    {
        $page = $this->onStep('3')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('Step 3 of 5: AI account', $page->filter('.wizard .step-count')->text());
        self::assertSame(['done', 'done', 'current', 'upcoming', 'upcoming'], $page->filter('.wizard .steps li')->each(static fn (Crawler $marker): string => (string) $marker->attr('class')));
        self::assertSame(['3'], $page->filter('.wizard .steps li[aria-current="step"]')->each(static fn (Crawler $marker): string => (string) $marker->attr('data-s')));
    }

    public function testEachProgressMarkerLinksItsStepWithItsNumberAndLabel(): void
    {
        $markers = $this->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY'])->filter('.wizard .steps li a');

        self::assertSame(['/setup?step=1', '/setup?step=2', '/setup?step=3', '/setup?step=4', '/setup?step=5'], $markers->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertSame(['1 Repository', '2 GitHub App', '3 AI account', '4 Check', '5 Done'], $markers->each(static fn (Crawler $link): string => $link->text()));
    }

    /** @return iterable<string, array{string}> */
    public static function stepsOutsideTheWizard(): iterable
    {
        yield 'zero' => ['0'];
        yield 'one past the last' => ['6'];
        yield 'negative' => ['-1'];
        yield 'a word' => ['abc'];
        yield 'a fraction' => ['2.5'];
        yield 'empty' => [''];
    }

    #[DataProvider('stepsOutsideTheWizard')]
    public function testAStepOutsideTheWizardOpensTheFirstStep(string $step): void
    {
        $page = $this->onStep($step)->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('Step 1 of 5: Repository', $page->filter('.wizard .step-count')->text());
    }

    /** @return iterable<string, array{string, string}> */
    public static function stepTitles(): iterable
    {
        yield 'repository' => ['1', 'Connect your repository'];
        yield 'GitHub App' => ['2', "Create Intakt's GitHub App"];
        yield 'AI account' => ['3', 'Add an AI account'];
        yield 'check' => ['4', 'Check the project'];
        yield 'ready' => ['5', 'Ready to start'];
    }

    #[DataProvider('stepTitles')]
    public function testEachStepHasTheMocksTitleAsItsOneHeading(string $step, string $title): void
    {
        $page = $this->onStep($step)->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame([$title], $page->filter('main h1')->each(static fn (Crawler $heading): string => $heading->text()));
    }

    /** @return iterable<string, array{string, string}> */
    public static function stepsAfterTheFirst(): iterable
    {
        yield 'GitHub App' => ['2', '/setup?step=1'];
        yield 'AI account' => ['3', '/setup?step=2'];
        yield 'check' => ['4', '/setup?step=3'];
        yield 'ready' => ['5', '/setup?step=4'];
    }

    #[DataProvider('stepsAfterTheFirst')]
    public function testEachStepButTheFirstHasABackLinkToTheStepBefore(string $step, string $before): void
    {
        $page = $this->onStep($step)->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame([$before], $page->filter('.step-nav a.btn.quiet')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
    }

    public function testTheFirstStepHasNoBackLink(): void
    {
        $page = $this->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertCount(0, $page->filter('.step-nav a.btn.quiet'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function stepsBeforeTheLast(): iterable
    {
        yield 'repository' => ['1', '/setup?step=2'];
        yield 'GitHub App' => ['2', '/setup?step=3'];
        yield 'check' => ['4', '/setup?step=5'];
    }

    #[DataProvider('stepsBeforeTheLast')]
    public function testAStepWithoutAFormContinuesToTheStepAfterWithItsOnePrimaryButton(string $step, string $after): void
    {
        $page = $this->onStep($step)->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame([$after], $page->filter('main .btn.primary')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertSame(['Continue'], $page->filter('.step-nav .btn.primary')->each(static fn (Crawler $link): string => $link->text()));
    }

    public function testTheRepositoryStepOffersTheFieldWithItsHintAndNoErrorBeforeItIsChecked(): void
    {
        $page = $this->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        $form = $page->filter('#step-repository form.check-repository');
        self::assertSame('get', $form->attr('method'));
        self::assertSame('/setup', $form->attr('action'));
        self::assertSame('1', $form->filter('input[type=hidden][name=step]')->attr('value'));
        self::assertSame('Repository address', $form->filter('label[for=repo]')->text());
        self::assertCount(1, $form->filter('input#repo.input[name=repository]'));
        self::assertCount(0, $page->filter('.field-error, [aria-invalid], .detected'));
    }

    public function testAnEmptyRepositoryAddressShowsTheErrorAndStaysOnStepOne(): void
    {
        $page = $this->onStep('1&repository=')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('Step 1 of 5: Repository', $page->filter('.wizard .step-count')->text());
        self::assertSame(RepositoryField::EMPTY, $page->filter('#step-repository .field-error')->text());
        self::assertSame('true', $page->filter('input#repo')->attr('aria-invalid'));
        self::assertSame('repo-hint repo-error', $page->filter('input#repo')->attr('aria-describedby'));
        self::assertCount(0, $page->filter('.detected'));
    }

    public function testARepositoryOnAnotherHostIsRefusedAndKeptInTheField(): void
    {
        $page = $this->onStep('1&repository=https%3A%2F%2Fgitlab.com%2Facme%2Ffield-notes')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame(RepositoryField::NOT_GITHUB, $page->filter('#step-repository .field-error')->text());
        self::assertSame('https://gitlab.com/acme/field-notes', $page->filter('input#repo')->attr('value'));
    }

    public function testAGitHubAddressIsShownAsTheDetectedRepository(): void
    {
        $page = $this->onStep('1&repository=https%3A%2F%2Fgithub.com%2Facme%2Ffield-notes')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('GitHub, repository acme/field-notes', $page->filter('#step-repository .detected p')->text());
        self::assertCount(0, $page->filter('.field-error, [aria-invalid]'));
    }

    public function testTheRepositoryStepSaysThatNothingKeepsTheAddressYet(): void
    {
        $page = $this->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertStringStartsWith('This step checks the address only; Intakt does not keep it yet.', $page->filter('#step-repository .not-wired')->text());
    }

    public function testATypedAddressWithMarkupIsEscaped(): void
    {
        $page = $this->onStep('1&repository=%3Cb%3Ebold%3C%2Fb%3E')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertCount(0, $page->filter('main b'));
        self::assertSame('<b>bold</b>', $page->filter('input#repo')->attr('value'));
    }

    public function testTheGitHubAppStepOffersNoWorkingButtonAndSaysWhatIntaktReadsInstead(): void
    {
        $page = $this->onStep('2')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        $button = $page->filter('#step-github-app button');
        self::assertSame('Create the app on GitHub', $button->text());
        self::assertNotNull($button->attr('disabled'));
        self::assertSame('app-not-wired', $button->attr('aria-describedby'));
        self::assertStringContainsString('GITHUB_APP_ID, GITHUB_APP_INSTALLATION_ID and GITHUB_APP_PRIVATE_KEY', $page->filter('#app-not-wired')->text());
        self::assertCount(0, $page->filter('#step-github-app form'));
    }

    public function testWithOneKeyTheProviderStepIsDoneAndNamesTheProviderThatHasIt(): void
    {
        $page = $this->onStep('3')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('done', $page->filter('#step-providers')->attr('data-state'));
        self::assertSame('anthropic has a key.', $page->filter('#step-providers .lead')->text());
    }

    public function testWithOneKeyEveryOtherProviderIsNamedWithTheVariableToSet(): void
    {
        $page = $this->onStep('3')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame([
            'Provider "openai" is disabled: set OPENAI_API_KEY to enable it.',
            'Provider "openai_compatible" is disabled: set OPENAI_COMPATIBLE_BASE_URL and OPENAI_COMPATIBLE_API_KEY to enable it.',
            'Provider "claude_code" is disabled: set CLAUDE_CODE_OAUTH_TOKEN to enable it.',
        ], $page->filter('#step-providers .keyless li')->each(static fn (Crawler $line): string => $line->text()));
    }

    public function testEachTierOfTheFirstProjectShowsItsModelOrWhyItHasNone(): void
    {
        $page = $this->onStep('3')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('The tiers of the project sample:', $page->filter('#step-providers .tiers-of')->text());
        self::assertSame([
            'strong anthropic big',
            'standard anthropic middle',
            'light - Project "sample" maps the "light" tier to a disabled provider. Provider "openai" is disabled: set OPENAI_API_KEY to enable it.',
        ], $page->filter('#step-providers .tiers tbody tr')->each(static fn (Crawler $row): string => implode(' ', $row->filter('td')->each(static fn (Crawler $cell): string => $cell->text()))));
    }

    public function testWithNoKeyTheProviderStepIsMissingAndEveryProviderNamesItsVariable(): void
    {
        $page = $this->onStep('3')->setupPage(self::PROJECTS, []);

        self::assertSame('missing', $page->filter('#step-providers')->attr('data-state'));
        self::assertSame('No provider has a key yet: paste one below, or set the variable named below for a provider you use.', $page->filter('#step-providers .lead')->text());
        self::assertCount(4, $page->filter('#step-providers .keyless li'));
        self::assertStringContainsString('set ANTHROPIC_API_KEY to enable it.', $page->filter('#step-providers .keyless')->text());
    }

    public function testWithNoKeyTheProviderStepOffersOneKeyFieldWithTheFormToken(): void
    {
        $page = $this->onStep('3')->setupPage(self::PROJECTS, []);

        $form = $page->filter('#step-providers form.keep-provider-key');
        self::assertSame('/setup/provider-key', $form->attr('action'));
        self::assertNotSame('', $form->filter('input[name=_token]')->attr('value'));
        self::assertCount(1, $form->filter('input[type=password][name=key][autocomplete=off]'));
        self::assertNull($form->filter('input[name=key]')->attr('value'));
    }

    public function testWithNoKeyTheKeyFieldIsDrawnAsADashboardInput(): void
    {
        $page = $this->onStep('3')->setupPage(self::PROJECTS, []);

        self::assertCount(1, $page->filter('#step-providers form.keep-provider-key input#provider-key.input'));
    }

    public function testWithNoKeyTheStepsPrimaryButtonSubmitsTheKeyFormAndSkipLeadsOn(): void
    {
        $page = $this->onStep('3')->setupPage(self::PROJECTS, []);

        $button = $page->filter('.step-nav .btn.primary');
        self::assertSame(['Keep the key'], $button->each(static fn (Crawler $primary): string => $primary->text()));
        self::assertSame('submit', $button->attr('type'));
        self::assertSame('keep-provider-key', $button->attr('form'));
        self::assertSame('/setup?step=4', $page->filter('.step-nav a.btn.secondary')->attr('href'));
    }

    public function testTheKeyFieldIsLabelledWithTheCatalogsProviders(): void
    {
        $page = $this->onStep('3')->setupPage(self::PROJECTS, []);

        self::assertSame('A key or token for Anthropic, OpenAI or Hugging Face', $page->filter('#step-providers label[for=provider-key]')->text());
    }

    public function testWithAKeyedProviderTheProviderStepOffersNoKeyField(): void
    {
        $page = $this->onStep('3')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertCount(0, $page->filter('#step-providers form'));
        self::assertSame('/setup?step=4', $page->filter('.step-nav a.btn.primary')->attr('href'));
    }

    public function testAKeptKeyShowsItsProviderAndAtMostItsLastFourCharactersAndNoField(): void
    {
        $directory = $this->keptKeyDirectory();
        // Kept after the container is built, as the page keeps it in a running install, because a container built with the kept key turns its provider on.
        $this->onStep('3')->setupPage(self::PROJECTS, [], $directory);
        new KeptProviderKeyFile($directory, new SymfonyAiProviderCatalog())->keep(ProviderKey::recognise('hf_'.self::KEPT_BODY, new SymfonyAiProviderCatalog()));

        $page = $this->browser()->request('GET', '/setup?step=3');

        self::assertSame('done', $page->filter('#step-providers')->attr('data-state'));
        self::assertSame('The huggingface key ending in '.substr(self::KEPT_BODY, -4).' is kept. Restart Intakt to use it.', $page->filter('#step-providers .lead')->text());
        self::assertCount(0, $page->filter('#step-providers form'));
        self::assertStringNotContainsString(self::KEPT_BODY, (string) $this->browser()->getResponse()->getContent());
    }

    public function testAKeptHuggingFaceTokenTurnsOnTheOpenAiCompatibleEndpointOnceTheContainerIsBuiltWithIt(): void
    {
        $directory = $this->keptKeyDirectory();
        new KeptProviderKeyFile($directory, new SymfonyAiProviderCatalog())->keep(ProviderKey::recognise('hf_'.self::KEPT_BODY, new SymfonyAiProviderCatalog()));

        $page = $this->onStep('3')->setupPage(self::PROJECTS, [], $directory);

        self::assertSame('openai_compatible has a key.', $page->filter('#step-providers .lead')->text());
    }

    public function testAnUnreadableKeptKeyIsShownAsTheStepsErrorInsteadOfOfferingTheField(): void
    {
        $directory = $this->keptKeyDirectory();
        new Filesystem()->dumpFile($directory.'/provider-key.json', 'not json');

        $page = $this->onStep('3')->setupPage(self::PROJECTS, [], $directory);

        $lead = $page->filter('#step-providers .lead.step-error[role=alert]');
        self::assertStringStartsWith('The key kept in the browser cannot be read', $lead->text());
        self::assertCount(0, $page->filter('#step-providers form'));
    }

    public function testAReadableKeyIsNoError(): void
    {
        $page = $this->onStep('3')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertCount(0, $page->filter('#step-providers .step-error, #step-providers [role=alert]'));
    }

    public function testWithNoKeyNoTierOfTheFirstProjectHasAModel(): void
    {
        $page = $this->onStep('3')->setupPage(self::PROJECTS, []);

        self::assertSame(['-', '-', '-'], $page->filter('#step-providers .tiers tbody td:nth-child(2)')->each(static fn (Crawler $cell): string => $cell->text()));
    }

    public function testTheCheckStepListsEachProjectWithTheReviewersOfItsPanel(): void
    {
        $page = $this->onStep('4')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('done', $page->filter('#step-project')->attr('data-state'));
        self::assertSame('2 projects are configured.', $page->filter('#step-project .checked-count')->text());
        self::assertSame(['sample: review panel', 'beta: review panel'], $page->filter('#step-project li[data-panel] strong')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSame('ok', $page->filter('#step-project li[data-panel]')->first()->attr('data-panel'));
        self::assertSame(
            'Reviewers: correctness (strong tier on anthropic), security (standard tier on anthropic).',
            $page->filter('#step-project li[data-panel] p')->first()->text(),
        );
    }

    public function testAProjectWithoutAPanelIsShownAsABrokenCheckWithTheReason(): void
    {
        $page = $this->onStep('4')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        $beta = $page->filter('#step-project li[data-panel][data-project=beta]');
        self::assertSame('broken', $beta->attr('data-panel'));
        self::assertCount(1, $beta->filter('svg.broken-i'));
        self::assertSame('Project "beta" has no review panel configured.', $beta->filter('p')->text());
    }

    public function testAProjectWhoseMainFailedToBootShowsTheRedactedReason(): void
    {
        // The page holds the key's value as test-value, so a reason that quotes it must lose it.
        $this->ledger->failMainBoot('sample', 'The harness exited with 1: key test-value refused.');

        $page = $this->onStep('4')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        $boot = $page->filter('#step-project li[data-boot][data-project=sample]');
        self::assertSame('failed', $boot->attr('data-boot'));
        self::assertSame('sample: main does not boot', $boot->filter('strong')->text());
        self::assertSame('The harness exited with 1: key '.Redaction::MARKER.' refused.', $boot->filter('p')->text());
        self::assertStringNotContainsString('test-value', $page->filter('#step-project')->html());
    }

    public function testAProjectWithoutAFailedBootOfMainSaysNoneIsRecorded(): void
    {
        $this->ledger->failMainBoot('sample', 'The harness exited with 1.');

        $page = $this->onStep('4')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        $boot = $page->filter('#step-project li[data-boot][data-project=beta]');
        self::assertSame('no-failure', $boot->attr('data-boot'));
        self::assertSame('beta: no failed boot of main recorded', $boot->filter('strong')->text());
    }

    public function testAMainThatBootedAgainIsNoLongerShownAsFailing(): void
    {
        $this->ledger->failMainBoot('sample', 'The harness exited with 1.');
        $this->ledger->bootMain('sample');

        $page = $this->onStep('4')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame(['no-failure', 'no-failure'], $page->filter('#step-project li[data-boot]')->each(static fn (Crawler $line): string => (string) $line->attr('data-boot')));
    }

    public function testTheCheckStepReadsAgainFromItsOwnLink(): void
    {
        $page = $this->onStep('4')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('/setup?step=4', $page->filter('.step-nav a.btn.secondary')->attr('href'));
    }

    public function testWithNoProjectTheCheckStepShowsTheMissingProjectAsItsError(): void
    {
        $page = $this->onStep('4')->setupPage("projects: {}\n", ['ANTHROPIC_API_KEY']);

        self::assertSame('missing', $page->filter('#step-project')->attr('data-state'));
        self::assertSame('No project is configured yet: add one to the project file that INTAKT_CONFIG names.', $page->filter('#step-project .step-error[role=alert]')->text());
        self::assertCount(0, $page->filter('#step-project .checks'));
    }

    public function testWithNoProjectTheAiAccountStepListsNoTiers(): void
    {
        $page = $this->onStep('3')->setupPage("projects: {}\n", ['ANTHROPIC_API_KEY']);

        self::assertCount(0, $page->filter('#step-providers .tiers'));
    }

    public function testThePageShowsNoAgentStepNoAddressAndNoCommandToCopy(): void
    {
        $_SERVER['MCP_OWNER_TOKEN'] = self::OWNER_TOKEN;
        $_SERVER['INTAKT_COMPOSE_SERVICE'] = 'intakt';

        $page = $this->onStep('5')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);
        $html = (string) $this->browser()->getResponse()->getContent();

        self::assertCount(0, $page->filter('#step-agent, main pre, main code'));
        self::assertStringNotContainsString('/mcp/owner', $html);
        self::assertStringNotContainsString('claude mcp add', $html);
        self::assertStringNotContainsString(self::OWNER_TOKEN, $html);
    }

    public function testTheColumnSitsInTheContentAreaWithoutItsWidthCap(): void
    {
        $page = $this->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertCount(1, $page->filter('main.content.wide > .setup.wizard'));
    }

    public function testWithTheSampleProjectAndNoReviewTheFirstReviewIsMissingAndOffersTheStart(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;

        $page = $this->onStep('5')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('missing', $page->filter('#step-first-review')->attr('data-state'));
        self::assertSame('No review has run yet: start the review of the change sample-change of the sample project.', $page->filter('#step-first-review .lead')->text());
        self::assertSame('/setup/first-review', $page->filter('#step-first-review form.start-first-review')->attr('action'));
        self::assertSame('post', $page->filter('#step-first-review form.start-first-review')->attr('method'));
    }

    public function testBeforeAReviewRanTheOnlyPrimaryButtonStartsTheFirstReview(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;

        $page = $this->onStep('5')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame(['Start the first review'], $page->filter('main .btn.primary')->each(static fn (Crawler $button): string => $button->text()));
        self::assertSame('submit', $page->filter('form.start-first-review .btn.primary')->attr('type'));
    }

    public function testAfterAReviewRanTheFirstReviewIsDoneAndLinksToItsReviewPage(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);

        $page = $this->onStep('5')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('done', $page->filter('#step-first-review')->attr('data-state'));
        self::assertSame('/reviews/round-1', $page->filter('#step-first-review a.btn.primary')->attr('href'));
        self::assertCount(0, $page->filter('form.start-first-review'));
    }

    public function testAfterAReviewRanTheOnlyPrimaryButtonOpensTheReview(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);

        $page = $this->onStep('5')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame(['Open the review'], $page->filter('main .btn.primary')->each(static fn (Crawler $button): string => $button->text()));
    }

    public function testTheReadyStepShowsTheMascotAsADecorativeImageInBothThemes(): void
    {
        $page = $this->onStep('5')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);
        $images = $page->filter('.fr-hero img.character');

        self::assertSame(['', ''], $images->each(static fn (Crawler $image): ?string => $image->attr('alt')));
        self::assertSame(1, preg_match('~octopus-conducting-light[^"]*\.webp$~', $images->eq(0)->attr('src') ?? ''));
        self::assertSame(1, preg_match('~octopus-conducting-dark[^"]*\.webp$~', $images->eq(1)->attr('src') ?? ''));
    }

    public function testTheReadyStepShowsOneOctopusAt144Px(): void
    {
        $images = $this->onStep('5')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY'])->filter('img[src*="octopus-"]');

        self::assertSame([['144', '144'], ['144', '144']], $images->each(static fn (Crawler $image): array => [$image->attr('width'), $image->attr('height')]));
    }

    public function testNoOtherStepShowsTheOctopus(): void
    {
        $page = $this->onStep('3')->setupPage(self::PROJECTS, []);

        self::assertCount(0, $page->filter('img[src*="octopus-"]'));
    }

    public function testAfterAnApprovedFirstReviewTheReadyStepShowsTheBowingOctopus(): void
    {
        $this->ledger->startRoundOfQuorum(SampleChange::CHANGE, 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 0);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeRound('round-1');

        $sources = $this->onStep('5')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY'])->filter('.fr-hero img.character')->each(static fn (Crawler $image): ?string => $image->attr('src'));

        self::assertSame(1, preg_match('~octopus-bowing-light~', $sources[0] ?? ''));
        self::assertSame(1, preg_match('~octopus-bowing-dark~', $sources[1] ?? ''));
    }

    public function testARunningFirstReviewIsShownInProgress(): void
    {
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);

        $page = $this->onStep('5')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('The first review, of the change sample-change of the alpha project, is in progress.', $page->filter('#step-first-review .lead')->text());
    }

    public function testAFirstReviewThatRanOutOfTimeSaysItEndedWithoutAJudgesCall(): void
    {
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);
        $this->now = '2026-10-30T12:00:00+00:00';

        $page = $this->onStep('5')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('The first review, of the change sample-change of the alpha project, ended without a call of the judge.', $page->filter('#step-first-review .lead')->text());
    }

    public function testAJudgedFirstReviewNamesItsOutcome(): void
    {
        $this->ledger->startRoundOfQuorum(SampleChange::CHANGE, 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 0);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeRound('round-1');

        $page = $this->onStep('5')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('The first review, of the change sample-change of the alpha project, is judged with the outcome approve.', $page->filter('#step-first-review .lead')->text());
    }

    public function testAJudgedFirstReviewWithoutARecordedQuorumSaysItsOutcomeWasNotRecorded(): void
    {
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        $page = $this->onStep('5')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('The first review, of the change sample-change of the alpha project, is judged; its outcome was not recorded.', $page->filter('#step-first-review .lead')->text());
    }

    public function testTheReadyStepLinksToTheLatestRoundOfTheFirstReviewInTheLedger(): void
    {
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-2', 2);
        $this->ledger->startRoundOf('feat/other', 'round-3', 1);

        $page = $this->onStep('5')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('/reviews/round-2', $page->filter('#step-first-review a.btn.primary')->attr('href'));
    }

    public function testWithoutASampleRepositoryAndNoReviewTheFirstReviewIsNotNeededAndTheStepHasNoPrimaryButton(): void
    {
        $page = $this->onStep('5')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('not-needed', $page->filter('#step-first-review')->attr('data-state'));
        self::assertSame(
            'No review has run yet, and this page has no sample change to start one from: your coding agent can ask for a review of a change of your own project.',
            $page->filter('#step-first-review .lead')->text(),
        );
        self::assertCount(0, $page->filter('main .btn.primary'));
        self::assertCount(0, $page->filter('form.start-first-review'));
    }

    public function testWithoutTheSampleProjectTheFirstReviewIsNotNeeded(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;

        $page = $this->onStep('5')->setupPage(<<<'YAML'
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

    public function testAStartWhoseHandlerNamesNoRoundComesBackToTheReadyStep(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $commands = $this->commandBus();
        $token = $this->tokenFromThePage();
        $commands->expects(self::once())->method('dispatch')->willReturn(new Envelope(new stdClass()));

        $this->browser()->request('POST', '/setup/first-review', ['_token' => $token]);

        self::assertResponseRedirects('/setup?step=5');
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

        self::assertResponseRedirects('/setup?step=5');
        $this->browser()->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Round 1 was asked for');
        self::assertStringNotContainsString('Stack trace', (string) $this->browser()->getResponse()->getContent());
    }

    public function testAStartWithoutTheTokenDispatchesNothing(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $this->commandBus()->expects(self::never())->method('dispatch');
        $this->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

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

    public function testTheReadyStepShowsTheRecordedReviewBeforeTheFirstReview(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;

        $page = $this->onStep('5')->setupPage(self::PROJECTS, []);

        self::assertSame('See a real review', $page->filter('#see-review h2')->text());
        self::assertSame(['see-review', 'step-first-review'], $page->filter('#step-ready #see-review, #step-ready #step-first-review')->each(static fn (Crawler $part): string => (string) $part->attr('id')));
    }

    public function testWithoutARecordingTheHeroSaysSoInPlainWordsAndOffersNoButton(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $_SERVER['INTAKT_COMPOSE_SERVICE'] = 'intakt';

        $page = $this->onStep('5')->setupPage(self::PROJECTS, []);

        self::assertSame(DemoReadiness::NoRecording->firstRunLine(self::getContainer()->get(SetupSteps::class)->firstRun()), $page->filter('#see-review .next')->text());
        self::assertCount(0, $page->filter('form.play-demo'));
        self::assertCount(0, $page->filter('#see-review .fr-when'));
    }

    public function testWithoutARecordingOrAKeyTheHeroPointsToTheAiAccountStep(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $_SERVER['INTAKT_COMPOSE_SERVICE'] = 'intakt';

        $page = $this->onStep('5')->setupPage(self::PROJECTS, []);

        self::assertSame('This install has no recorded review. Add a key in step 3, AI account, to run one live.', $page->filter('#see-review .next')->text());
        self::assertSame('/setup?step=3', $page->filter('#see-review .next a')->attr('href'));
        self::assertSame('step 3, AI account', $page->filter('#see-review .next a')->text());
    }

    public function testWithoutARecordingAndWithAKeyTheHeroPointsToTheFirstReviewAndNotToAKeyForm(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $_SERVER['INTAKT_COMPOSE_SERVICE'] = 'intakt';

        $page = $this->onStep('5')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('This install has no recorded review. A live review can be started or opened below.', $page->filter('#see-review .next')->text());
        self::assertCount(1, $page->filter('#step-first-review form.start-first-review'));
    }

    public function testWithAKeyButNoSampleToStartTheHeroPromisesNoStart(): void
    {
        $_SERVER['INTAKT_COMPOSE_SERVICE'] = 'intakt';

        $page = $this->onStep('5')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertCount(0, $page->filter('form.start-first-review, #step-first-review a.btn'));
        self::assertSame('This install has no recorded review, and nothing below can start a live one yet.', $page->filter('#see-review .next')->text());
    }

    public function testTheReadyStepNamesNoCommandWhenTheSampleCannotStart(): void
    {
        $page = $this->onStep('5')->setupPage(self::PROJECTS, []);

        self::assertSame('not-needed', $page->filter('#step-first-review')->attr('data-state'));
        self::assertDoesNotMatchRegularExpression('/intakt:|bin\/console|docker /', $page->filter('main')->text());
    }

    public function testWithARecordingTheHeroHoldsThePlayTheDemoRunButtonAndSaysWhatHappens(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $_SERVER['INTAKT_COMPOSE_SERVICE'] = 'intakt';
        $_SERVER['INTAKT_DEMO_RECORDING'] = $this->file;

        $page = $this->onStep('5')->setupPage(self::PROJECTS, []);

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

        $page = $this->onStep('5')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('Start the first review', $page->filter('form.start-first-review .btn.secondary')->text());
    }

    public function testWithAReviewAndTheDemoReadyOpenTheReviewIsSecondaryAndPlayIsTheOnlyPrimaryButton(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $_SERVER['INTAKT_COMPOSE_SERVICE'] = 'intakt';
        $_SERVER['INTAKT_DEMO_RECORDING'] = $this->file;

        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);

        $page = $this->onStep('5')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('Open the review', $page->filter('a.btn.secondary')->text());
        self::assertSame(['Play the demo run'], $page->filter('main .btn.primary')->each(static fn (Crawler $button): string => $button->text()));
    }

    public function testTheReadyStepDrawsWhatTheReviewShowsWithACharacterAndAShortLabelEach(): void
    {
        $page = $this->onStep('5')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame(['Read', 'Check', 'To fix'], $page->filter('.fr-seq li strong')->each(static fn (Crawler $label): string => $label->text()));
        self::assertSame([2, 2, 2], $page->filter('.fr-seq li')->each(static fn (Crawler $step): int => $step->filter('img.character[alt=""]')->count()));
        self::assertCount(2, $page->filter('.fr-seq li')->eq(1)->filter('img[src*="judge-"]'));
        self::assertCount(2, $page->filter('.fr-seq li')->eq(2)->filter('img[src*="roles/developer-"][srcset]'));
    }

    public function testTheAiAccountStepHasNoDisclosure(): void
    {
        $page = $this->onStep('3')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertCount(0, $page->filter('main details'));
        self::assertCount(1, $page->filter('#step-providers .tiers'));
    }

    public function testAProjectNameWithMarkupIsEscaped(): void
    {
        $page = $this->onStep('4')->setupPage(<<<'YAML'
            projects:
                '<b>bold</b>':
                    tiers:
                        strong: { provider: anthropic, model: big }
            YAML, ['ANTHROPIC_API_KEY']);

        self::assertCount(0, $page->filter('main b'));
        self::assertSame('<b>bold</b>: review panel', $page->filter('#step-project li[data-panel] strong')->text());
    }

    protected function setUp(): void
    {
        $this->containers = self::compiledContainers();

        foreach (self::VARIABLES as $name) {
            $this->saved[$name] = ['server' => $_SERVER[$name] ?? null, 'env' => $_ENV[$name] ?? null, 'process' => getenv($name)];
            $this->unsetVariable($name);
        }

        $this->file = sys_get_temp_dir().'/intakt-setup-'.bin2hex(random_bytes(4)).'.yaml';
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

    // The rest of the query is written raw, so a test can also send a step that is no number or a repository field.
    private function onStep(string $query): self
    {
        $this->path = '/setup?step='.$query;

        return $this;
    }

    /**
     * @param list<string> $keys
     */
    private function setupPage(string $projects, array $keys, string $keptKeyDirectory = ''): Crawler
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
        $page = $browser->request('GET', $this->path);
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

    // Read from the Ready step's form, because the token belongs to the browser's session.
    private function tokenFromThePage(): string
    {
        return $this->onStep('5')->setupPage(self::PROJECTS, ['ANTHROPIC_API_KEY'])->filter('form.start-first-review input[name=_token]')->attr('value') ?? '';
    }

    private static function compiledContainers(): CompiledContainers
    {
        return CompiledContainers::beside(self::createKernel()->getCacheDir(), 'first-run');
    }

    private function keptKeyDirectory(): string
    {
        $this->keptDirectory = sys_get_temp_dir().'/'.uniqid('setup-kept-', true);

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
