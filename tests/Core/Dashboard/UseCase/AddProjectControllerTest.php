<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\AddProjectStep;
use Intakt\Core\Dashboard\Domain\RepositoryField;
use Intakt\Core\Dashboard\Domain\ShareAndLabelField;
use Intakt\Core\Dashboard\UseCase\AddProject\AddProjectController;
use Intakt\Core\Dashboard\UseCase\AddProject\BootChecks;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Watcher\Contract\FailingMain;
use Intakt\Core\Watcher\Contract\FailingMains;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(AddProjectController::class)]
#[CoversClass(BootChecks::class)]
#[UsesClass(AddProjectStep::class)]
#[UsesClass(RepositoryField::class)]
#[UsesClass(ShareAndLabelField::class)]
#[UsesClass(FailingMains::class)]
#[UsesClass(FailingMain::class)]
final class AddProjectControllerTest extends DashboardPageTestCase
{
    public function testWithoutAStepTheFlowOpensOnTheRepositoryStep(): void
    {
        $page = $this->browser->request('GET', '/projects/new');

        self::assertResponseIsSuccessful();
        self::assertSame('Step 1 of 5: Repository', $page->filter('.wizard .step-count')->text());
        self::assertSame(['Which repository?'], $page->filter('main h1')->each(static fn (Crawler $title): string => $title->text()));
    }

    public function testTheBreadcrumbLeadsBackToProjectsAndNamesThePage(): void
    {
        $page = $this->browser->request('GET', '/projects/new');

        self::assertSame('/projects', $page->filter('.crumbs a')->attr('href'));
        self::assertSame('Add a project', $page->filter('.crumbs [aria-current=page]')->text());
    }

    public function testTheRailKeepsProjectsCurrent(): void
    {
        $page = $this->browser->request('GET', '/projects/new');

        self::assertSame(['projects'], $page->filter('.rail a.nav[aria-current=page]')->each(static fn (Crawler $item): string => (string) $item->attr('data-k')));
    }

    public function testEachProgressMarkerLinksItsStepWithItsNumberAndLabel(): void
    {
        $markers = $this->browser->request('GET', '/projects/new')->filter('.wizard .steps li a');

        self::assertSame(['/projects/new?step=1', '/projects/new?step=2', '/projects/new?step=3', '/projects/new?step=4', '/projects/new?step=5'], $markers->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertSame(['1 Repository', '2 Install the App', '3 Check', '4 Share and label', '5 Start'], $markers->each(static fn (Crawler $link): string => $link->text()));
    }

    public function testOnStepThreeTheEarlierStepsAreDoneAndTheCountNamesTheStep(): void
    {
        $page = $this->browser->request('GET', '/projects/new?step=3');

        self::assertSame('Step 3 of 5: Check', $page->filter('.wizard .step-count')->text());
        self::assertSame(['done', 'done', 'current', 'upcoming', 'upcoming'], $page->filter('.wizard .steps li')->each(static fn (Crawler $marker): string => (string) $marker->attr('class')));
        self::assertSame(['3'], $page->filter('.wizard .steps li[aria-current="step"]')->each(static fn (Crawler $marker): string => (string) $marker->attr('data-s')));
    }

    /** @return iterable<string, array{string}> */
    public static function stepsOutsideTheFlow(): iterable
    {
        yield 'zero' => ['?step=0'];
        yield 'one past the last' => ['?step=6'];
        yield 'a word' => ['?step=abc'];
        yield 'a list' => ['?step%5B%5D=2'];
    }

    #[DataProvider('stepsOutsideTheFlow')]
    public function testAStepOutsideTheFlowOpensTheFirstStep(string $query): void
    {
        $page = $this->browser->request('GET', '/projects/new'.$query);

        self::assertSame('Step 1 of 5: Repository', $page->filter('.wizard .step-count')->text());
    }

    /** @return iterable<string, array{string, string}> */
    public static function stepTitles(): iterable
    {
        yield 'repository' => ['1', 'Which repository?'];
        yield 'install the App' => ['2', "Install Intakt's GitHub App on it"];
        yield 'check' => ['3', 'Check the project'];
        yield 'share and label' => ['4', 'Share and label'];
        yield 'start' => ['5', 'Ready to start'];
    }

    #[DataProvider('stepTitles')]
    public function testEachStepHasTheMocksTitleAsItsOneHeading(string $step, string $title): void
    {
        $page = $this->browser->request('GET', '/projects/new?step='.$step);

        self::assertSame([$title], $page->filter('main h1')->each(static fn (Crawler $heading): string => $heading->text()));
    }

    public function testTheFirstStepCancelsBackToProjects(): void
    {
        $page = $this->browser->request('GET', '/projects/new');

        self::assertSame(['Cancel'], $page->filter('.step-nav a.btn.quiet')->each(static fn (Crawler $link): string => $link->text()));
        self::assertSame('/projects', $page->filter('.step-nav a.btn.quiet')->attr('href'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function stepsAfterTheFirst(): iterable
    {
        yield 'install the App' => ['2', '/projects/new?step=1'];
        yield 'check' => ['3', '/projects/new?step=2'];
        yield 'share and label' => ['4', '/projects/new?step=3'];
        yield 'start' => ['5', '/projects/new?step=4'];
    }

    #[DataProvider('stepsAfterTheFirst')]
    public function testEachStepButTheFirstHasABackLinkToTheStepBefore(string $step, string $before): void
    {
        $page = $this->browser->request('GET', '/projects/new?step='.$step);

        self::assertSame([$before], $page->filter('.step-nav a.btn.quiet')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
    }

    /** @return iterable<string, array{string, string}> */
    public static function stepsBeforeTheLast(): iterable
    {
        yield 'repository' => ['1', '/projects/new?step=2'];
        yield 'install the App' => ['2', '/projects/new?step=3'];
        yield 'check' => ['3', '/projects/new?step=4'];
        yield 'share and label' => ['4', '/projects/new?step=5'];
    }

    #[DataProvider('stepsBeforeTheLast')]
    public function testEachStepButTheLastContinuesToTheStepAfterWithItsOnePrimaryButton(string $step, string $after): void
    {
        $page = $this->browser->request('GET', '/projects/new?step='.$step);

        self::assertSame([$after], $page->filter('main .btn.primary')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
    }

    public function testTheRepositoryStepPostsItsAddressToItself(): void
    {
        $form = $this->browser->request('GET', '/projects/new')->filter('#step-repository form.check-repository');

        self::assertSame('post', $form->attr('method'));
        self::assertSame('/projects/new?step=1', $form->attr('action'));
        self::assertCount(1, $form->filter('input#repo.input[name=repository]'));
    }

    public function testAnEmptyAddressShowsTheErrorAndStaysOnTheRepositoryStep(): void
    {
        $page = $this->browser->request('POST', '/projects/new?step=1', ['repository' => '']);

        self::assertSame('Step 1 of 5: Repository', $page->filter('.wizard .step-count')->text());
        self::assertSame(RepositoryField::EMPTY, $page->filter('#step-repository .field-error[role=alert]')->text());
        self::assertSame('true', $page->filter('input#repo')->attr('aria-invalid'));
        self::assertSame('repo-hint repo-error', $page->filter('input#repo')->attr('aria-describedby'));
    }

    public function testAMalformedAddressIsRefusedAndKeptInTheField(): void
    {
        $page = $this->browser->request('POST', '/projects/new?step=1', ['repository' => 'acme']);

        self::assertSame(RepositoryField::NOT_AN_ADDRESS, $page->filter('#step-repository .field-error')->text());
        self::assertSame('acme', $page->filter('input#repo')->attr('value'));
    }

    public function testAGitHubAddressIsShownAsTheDetectedRepository(): void
    {
        $page = $this->browser->request('POST', '/projects/new?step=1', ['repository' => 'https://github.com/acme/billing-api']);

        self::assertSame('GitHub, repository acme/billing-api', $page->filter('#step-repository .detected p')->text());
        self::assertCount(0, $page->filter('.field-error, [aria-invalid]'));
    }

    public function testCheckingAnAddressKeepsItsCredentialsOutOfTheUrl(): void
    {
        // Kept between the page and its post, so the ledger in memory stays for both.
        $this->browser->disableReboot();
        $form = $this->browser->request('GET', '/projects/new')->selectButton('Check the address')->form(['repository' => 'https://someone:marker@github.com/acme/billing-api']);

        $this->browser->submit($form);

        self::assertStringNotContainsString('marker', $this->browser->getRequest()->getUri());
        self::assertSame(RepositoryField::NOT_AN_ADDRESS, $this->browser->getCrawler()->filter('#step-repository .field-error')->text());
    }

    public function testARepositoryInTheQueryIsNotChecked(): void
    {
        $page = $this->browser->request('GET', '/projects/new?step=1&repository=acme%2Fbilling-api');

        self::assertCount(0, $page->filter('.field-error, [aria-invalid], .detected'));
        self::assertSame('', $page->filter('input#repo')->attr('value'));
    }

    public function testARepositoryPostedToAnotherStepIsIgnored(): void
    {
        $page = $this->browser->request('POST', '/projects/new?step=2', ['repository' => ['acme/billing-api']]);

        self::assertSame('Step 2 of 5: Install the App', $page->filter('.wizard .step-count')->text());
        self::assertCount(0, $page->filter('.field-error, .detected'));
    }

    public function testARepositorySentAsAListReadsAsEmpty(): void
    {
        $page = $this->browser->request('POST', '/projects/new?step=1', ['repository' => ['acme/billing-api']]);

        self::assertSame(RepositoryField::EMPTY, $page->filter('#step-repository .field-error')->text());
    }

    public function testATypedAddressWithMarkupIsEscaped(): void
    {
        $page = $this->browser->request('POST', '/projects/new?step=1', ['repository' => '<b>bold</b>']);

        self::assertCount(0, $page->filter('main b'));
        self::assertSame('<b>bold</b>', $page->filter('input#repo')->attr('value'));
    }

    public function testTheInstallStepOffersNoWorkingButtonAndSaysWhatIntaktUsesInstead(): void
    {
        $page = $this->browser->request('GET', '/projects/new?step=2');

        $button = $page->filter('#step-install-app button');
        self::assertSame('Install the App on GitHub', $button->text());
        self::assertNotNull($button->attr('disabled'));
        self::assertStringContainsString('GITHUB_APP_INSTALLATION_ID', $page->filter('#install-not-wired')->text());
    }

    public function testWithoutAFailedBootTheCheckStepSaysNoneIsRecorded(): void
    {
        $page = $this->browser->request('GET', '/projects/new?step=3');

        self::assertSame('no-failure', $page->filter('#step-check')->attr('data-state'));
        self::assertSame(['No failed boot of main recorded'], $page->filter('#step-check .checks strong')->each(static fn (Crawler $title): string => $title->text()));
    }

    public function testAProjectWhoseMainFailsToBootShowsItsReasonAsABrokenCheck(): void
    {
        $this->ledger->failMainBoot('billing-api', 'The harness exited with 1.');

        $page = $this->browser->request('GET', '/projects/new?step=3');

        $boot = $page->filter('#step-check li[data-boot=failed][data-project=billing-api]');
        self::assertSame('failed', $page->filter('#step-check')->attr('data-state'));
        self::assertSame('billing-api: main does not boot', $boot->filter('strong')->text());
        self::assertSame('The harness exited with 1.', $boot->filter('p')->text());
        self::assertCount(1, $boot->filter('svg.broken-i'));
    }

    public function testTheCheckStepShowsTheBootReasonRedacted(): void
    {
        $this->ledger->failMainBoot('billing-api', 'key held-value refused');
        $redactor = $this->createStub(Redactor::class);
        $redactor->method('redact')->willReturnCallback(static fn (string $text): string => str_replace('held-value', '[redacted]', $text));
        self::getContainer()->set(Redactor::class, $redactor);

        $page = $this->browser->request('GET', '/projects/new?step=3');

        self::assertSame('key [redacted] refused', $page->filter('#step-check li[data-boot=failed] p')->text());
        self::assertStringNotContainsString('held-value', $page->filter('#step-check')->html());
    }

    public function testAMainThatBootedAgainIsNoLongerShownAsFailing(): void
    {
        $this->ledger->failMainBoot('billing-api', 'The harness exited with 1.');
        $this->ledger->bootMain('billing-api');

        $page = $this->browser->request('GET', '/projects/new?step=3');

        self::assertCount(0, $page->filter('#step-check li[data-boot=failed]'));
    }

    public function testTheCheckStepRunsAgainFromItsOwnLink(): void
    {
        $page = $this->browser->request('GET', '/projects/new?step=3');

        self::assertSame('/projects/new?step=3', $page->filter('.step-nav a.btn.secondary')->attr('href'));
    }

    public function testTheShareAndLabelStepOffersTheDefaultLabelAndNoErrorBeforeItIsChecked(): void
    {
        $page = $this->browser->request('GET', '/projects/new?step=4');

        $form = $page->filter('#step-share-label form.check-share-label');
        self::assertSame('post', $form->attr('method'));
        self::assertSame('/projects/new?step=4', $form->attr('action'));
        self::assertSame('intakt', $form->filter('input#lbl[name=label]')->attr('value'));
        self::assertSame('', $form->filter('input#share[name=share]')->attr('value'));
        self::assertCount(0, $page->filter('.field-error, [aria-invalid], .detected'));
    }

    public function testAShareOutOfRangeAndAnEmptyLabelShowBothErrorsAndKeepTheStep(): void
    {
        $page = $this->browser->request('POST', '/projects/new?step=4', ['share' => '120', 'label' => '']);

        self::assertSame('Step 4 of 5: Share and label', $page->filter('.wizard .step-count')->text());
        self::assertSame([ShareAndLabelField::SHARE_OUT_OF_RANGE, ShareAndLabelField::LABEL_EMPTY], $page->filter('#step-share-label .field-error[role=alert]')->each(static fn (Crawler $error): string => $error->text()));
        self::assertSame('share-hint share-error', $page->filter('input#share')->attr('aria-describedby'));
        self::assertSame('120', $page->filter('input#share')->attr('value'));
        self::assertCount(0, $page->filter('.detected'));
    }

    public function testAGoodShareAndLabelAreShownAsChecked(): void
    {
        $page = $this->browser->request('POST', '/projects/new?step=4', ['share' => '20', 'label' => 'intakt']);

        self::assertSame('20% of the week, issues labeled intakt', $page->filter('#step-share-label .detected p')->text());
        self::assertCount(0, $page->filter('.field-error, [aria-invalid]'));
    }

    public function testAShareSentInTheQueryIsNotChecked(): void
    {
        $page = $this->browser->request('GET', '/projects/new?step=4&share=120&label=');

        self::assertCount(0, $page->filter('.field-error, .detected'));
    }

    public function testALabelWithMarkupIsEscaped(): void
    {
        $page = $this->browser->request('POST', '/projects/new?step=4', ['share' => '20', 'label' => '<b>bold</b>']);

        self::assertCount(0, $page->filter('main b'));
        self::assertSame('<b>bold</b>', $page->filter('input#lbl')->attr('value'));
    }

    public function testTheStartStepsButtonIsDisabledAndSaysWhyItDoesNothing(): void
    {
        $page = $this->browser->request('GET', '/projects/new?step=5');

        $start = $page->filter('#step-start .step-nav button.btn.primary');
        self::assertSame('Start', $start->text());
        self::assertNotNull($start->attr('disabled'));
        self::assertStringStartsWith('Starting a project from this page is not built yet', $page->filter('#start-not-wired')->text());
    }
}
