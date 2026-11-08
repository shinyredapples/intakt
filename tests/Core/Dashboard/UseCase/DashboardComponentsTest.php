<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\GlossaryTerm;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;
use Twig\Error\RuntimeError;

#[CoversNothing]
final class DashboardComponentsTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function plainButtonKinds(): iterable
    {
        yield 'primary' => ['primary'];
        yield 'secondary' => ['secondary'];
        yield 'quiet' => ['quiet'];
        yield 'needs' => ['needs'];
    }

    #[DataProvider('plainButtonKinds')]
    public function testEachPlainButtonKindCarriesItsClassAndNoArming(string $kind): void
    {
        $button = $this->render('button', ['kind' => $kind, 'label' => 'Approve a3f9c21'])->filter('button');

        self::assertSame('btn '.$kind, $button->attr('class'));
        self::assertSame('button', $button->attr('type'));
        self::assertSame('Approve a3f9c21', $button->text());
        self::assertNull($button->attr('data-controller'));
    }

    public function testADangerButtonIsArmedByItsFirstClickAndAsksToClickAgain(): void
    {
        $button = $this->render('button', [
            'kind' => 'danger',
            'label' => 'Pause everything',
            'confirm' => 'Click again to pause',
            'type' => 'submit',
        ])->filter('button');

        self::assertSame('btn danger', $button->attr('class'));
        self::assertSame('arm', $button->attr('data-controller'));
        self::assertSame('arm#click', $button->attr('data-action'));
        self::assertSame('Click again to pause', $button->attr('data-arm-confirm-value'));
        self::assertSame('submit', $button->attr('type'));
    }

    public function testAWaitingButtonShowsItIsBusy(): void
    {
        $button = $this->render('button', ['kind' => 'primary', 'label' => 'Saving…', 'busy' => true, 'small' => true])->filter('button');

        self::assertSame('btn primary sm is-loading', $button->attr('class'));
        self::assertSame('true', $button->attr('aria-busy'));
    }

    public function testADisabledButtonCannotBePressed(): void
    {
        $button = $this->render('button', ['kind' => 'secondary', 'label' => 'Test', 'disabled' => true])->filter('button');

        self::assertNotNull($button->attr('disabled'));
    }

    public function testAStateChipShowsItsDotAndWords(): void
    {
        $chip = $this->render('chip', ['label' => 'In review, round 2', 'dot' => 'ok', 'on_page' => true])->filter('.chip');

        self::assertSame('chip on-page strong', $chip->attr('class'));
        self::assertCount(1, $chip->filter('.dot.ok'));
        self::assertSame('In review, round 2', $chip->text());
    }

    public function testABrokenChipIsRedRatherThanStrong(): void
    {
        $chip = $this->render('chip', ['label' => 'CI broken', 'icon' => 'broken', 'broken' => true])->filter('.chip');

        self::assertSame('chip broken', $chip->attr('class'));
        self::assertCount(1, $chip->filter('svg.i.s'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function reasons(): iterable
    {
        yield 'budget' => ['budget', 'Budget', 'chip strong'];
        yield 'overlap' => ['overlap', 'Overlap', 'chip strong'];
        yield 'dependency' => ['dependency', 'Dependency', 'chip strong'];
        yield 'ci' => ['ci', 'CI broken', 'chip broken'];
    }

    #[DataProvider('reasons')]
    public function testEachReasonBadgeShowsAnIconAndItsWordInNeutralColorsUnlessCiIsBroken(string $reason, string $word, string $class): void
    {
        $badge = $this->render('reason_badge', ['reason' => $reason])->filter('.chip');

        self::assertSame($class, $badge->attr('class'));
        self::assertSame($word, $badge->text());
        self::assertCount(1, $badge->filter('svg.i'));
    }

    public function testAReasonBadgeMayNameWhatItWaitsFor(): void
    {
        $badge = $this->render('reason_badge', ['reason' => 'dependency', 'label' => 'Needs #612'])->filter('.chip');

        self::assertSame('Needs #612', $badge->text());
    }

    public function testAnUnknownReasonFailsTheRender(): void
    {
        $this->expectException(RuntimeError::class);

        $this->render('reason_badge', ['reason' => 'weather']);
    }

    public function testACountIsNeutral(): void
    {
        $count = $this->render('count', ['n' => 3])->filter('span');

        self::assertSame('count', $count->attr('class'));
        self::assertSame('3', $count->text());
    }

    public function testTheWaitingOnYouCountIsAmber(): void
    {
        self::assertSame('count needs', $this->render('count', ['n' => 4, 'needs' => true])->filter('span')->attr('class'));
    }

    public function testACardShowsNumberProjectTitleAndTheMetaLineWithoutMiddleDots(): void
    {
        $card = $this->render('card', $this->card())->filter('a.card');

        self::assertSame('card', $card->attr('class'));
        self::assertSame('/tasks/612', $card->attr('href'));
        self::assertSame('#612', $card->filter('.top span')->first()->text());
        self::assertSame('intakt', $card->filter('.top .pname')->text());
        self::assertSame('Command overlay for tasks and settings', $card->filter('.title')->text());
        self::assertSame(['42 min', 'Slot 1, Model L', '9b3e5aa'], $card->filter('.meta > span')->each(static fn (Crawler $fact): string => $fact->text()));
        self::assertCount(1, $card->filter('.meta > span.mono'));
        self::assertCount(1, $card->filter('.meta > span svg.i.s'));
        self::assertStringNotContainsString('·', $card->text());
        self::assertCount(0, $card->filter('.blocker'));
    }

    public function testACardsEmphasisLineSitsRightUnderTheTitle(): void
    {
        $card = $this->render('card', [...$this->card(), 'emphasis' => ['icon' => 'link', 'text' => 'Needs #612 merged first']])->filter('a.card');

        $emphasis = $card->filter('.title + p.blocker');
        self::assertSame('blocker', $emphasis->attr('class'));
        self::assertSame('Needs #612 merged first', $emphasis->text());
    }

    public function testABrokenEmphasisLineIsRed(): void
    {
        $card = $this->render('card', [...$this->card(), 'emphasis' => ['icon' => 'broken', 'text' => 'Waiting for CI: image build failing', 'broken' => true]]);

        self::assertSame('blocker broken', $card->filter('p.blocker')->attr('class'));
    }

    public function testANeedsYouCardIsAmberWithTheDotAndTheAsk(): void
    {
        $card = $this->render('card', [...$this->card(), 'ask' => 'Approve this version: it touches secret handling'])->filter('a.card');

        self::assertSame('card needs', $card->attr('class'));
        self::assertCount(1, $card->filter('.top .dot.needs'));
        self::assertSame('Approve this version: it touches secret handling', $card->filter('.title + p.blocker')->text());
    }

    public function testAListRowShowsTitleExplanationAndActionsOnTheRight(): void
    {
        $row = $this->embed('list_row', ['title' => 'Phone (home)', 'explanation' => 'Gets a push when something needs you.'], 'actions', '<button class="btn quiet sm">Remove</button>')->filter('.row');

        self::assertSame('Phone (home)', $row->filter('.body .t')->text());
        self::assertSame('Gets a push when something needs you.', $row->filter('.body .d')->text());
        self::assertSame('Remove', $row->filter('.side .btn')->text());
    }

    public function testASettingRowLabelsItsControl(): void
    {
        $row = $this->settingRow([]);

        self::assertSame('quiet-hours', $row->filter('label.l')->attr('for'));
        self::assertSame('Quiet hours', $row->filter('label.l')->text());
        self::assertSame('No pushes in these hours.', $row->filter('.txt .d')->text());
        self::assertCount(1, $row->filter('.ctl #quiet-hours'));
        self::assertSame('sstate', $row->filter('.sstate')->attr('class'));
        self::assertSame('', $row->filter('.sstate')->text());
    }

    public function testASettingRowSaysWhileItSaves(): void
    {
        $state = $this->settingRow(['state' => 'saving'])->filter('.sstate');

        self::assertSame('sstate saving', $state->attr('class'));
        self::assertSame('Saving…', $state->text());
        self::assertSame('polite', $state->attr('aria-live'));
    }

    public function testASettingRowSaysItSavedWithACheck(): void
    {
        $state = $this->settingRow(['state' => 'saved'])->filter('.sstate');

        self::assertSame('sstate saved', $state->attr('class'));
        self::assertSame('Saved', $state->text());
        self::assertCount(1, $state->filter('svg.i'));
    }

    public function testAFailedSettingRowNamesTheValueStillInForceAndOffersRetry(): void
    {
        $state = $this->settingRow(['state' => 'failed', 'still' => '22:00 to 07:00'])->filter('.sstate');

        self::assertSame('sstate failed', $state->attr('class'));
        self::assertSame('Not saved, still 22:00 to 07:00. Retry', $state->text());
        self::assertSame('Retry', $state->filter('button.linkbtn')->text());
    }

    public function testAnInputWithAnErrorIsMarkedInvalidAndDescribedByIt(): void
    {
        $page = $this->render('input', ['id' => 'port', 'name' => 'port', 'value' => '80', 'error' => 'Use a port from 1024 to 65535.']);

        $input = $page->filter('input.input');
        self::assertSame('80', $input->attr('value'));
        self::assertSame('true', $input->attr('aria-invalid'));
        self::assertSame('port-error', $input->attr('aria-describedby'));
        self::assertSame('Use a port from 1024 to 65535.', $page->filter('#port-error.field-error')->text());
    }

    public function testAnInputWithoutAnErrorIsNotMarkedInvalid(): void
    {
        $page = $this->render('input', ['id' => 'port', 'name' => 'port', 'value' => '8471']);

        self::assertNull($page->filter('input')->attr('aria-invalid'));
        self::assertCount(0, $page->filter('.field-error'));
    }

    public function testASelectChoosesTheSelectedOption(): void
    {
        $select = $this->render('select', [
            'id' => 'zone',
            'name' => 'zone',
            'selected' => 'Europe/Berlin',
            'options' => [['value' => 'UTC', 'label' => 'UTC'], ['value' => 'Europe/Berlin', 'label' => 'Europe/Berlin']],
        ])->filter('select.select');

        self::assertSame(['Europe/Berlin'], $select->filter('option[selected]')->each(static fn (Crawler $option): string => $option->text()));
    }

    public function testASwitchIsACheckboxWithTheSwitchRole(): void
    {
        $input = $this->render('switch', ['id' => 'lan', 'name' => 'lan', 'on' => true])->filter('.switch input');

        self::assertSame('switch', $input->attr('role'));
        self::assertNotNull($input->attr('checked'));
    }

    public function testABarLaysUsedReservedAndForecastEndToEnd(): void
    {
        $bar = $this->render('bar', ['label' => 'This week', 'note' => 'Resets at 14:00', 'used' => 46, 'reserved' => 12, 'forecast' => 18]);

        self::assertSame('This week', $bar->filter('.wl strong')->text());
        self::assertSame('Resets at 14:00', $bar->filter('.wl .reset')->text());
        self::assertSame('width: 46%', $bar->filter('.bar .used')->attr('style'));
        self::assertSame('left: 46%; width: 12%', $bar->filter('.bar .reserved')->attr('style'));
        self::assertSame('left: 58%; width: 18%', $bar->filter('.bar .forecast')->attr('style'));
        self::assertSame('Used 46%, reserved 12%, forecast 18%', $bar->filter('.bar')->attr('aria-label'));
    }

    public function testABarCutsSegmentsAtTheEndOfItsTrack(): void
    {
        $bar = $this->render('bar', ['label' => 'Full', 'note' => 'Share used up', 'used' => 130, 'reserved' => 10]);

        self::assertSame('width: 100%', $bar->filter('.bar .used')->attr('style'));
        self::assertCount(0, $bar->filter('.bar .reserved, .bar .forecast'));
    }

    public function testTheBarLegendNamesEachSegment(): void
    {
        self::assertSame(['Used', 'Reserved', 'Forecast'], $this->render('bar_legend', [])->filter('.legend span')->each(static fn (Crawler $item): string => $item->text()));
    }

    public function testABannerShowsItsIconSentenceAndOneAction(): void
    {
        $banner = $this->banner(['broken' => false])->filter('.banner');

        self::assertSame('banner', $banner->attr('class'));
        self::assertSame('field-notes is paused. Nothing new starts.', $banner->filter('p')->text());
        self::assertCount(1, $banner->filter(':scope > svg.i'));
        self::assertSame('Resume', $banner->filter(':scope > .btn')->text());
    }

    public function testABrokenBannerCarriesTheBrokenClass(): void
    {
        self::assertSame('banner broken', $this->banner(['broken' => true])->filter('.banner')->attr('class'));
    }

    public function testABannerCollapsesOnPhonesBehindAToggleTheDisclosureControllerOpens(): void
    {
        $banner = $this->banner(['broken' => false])->filter('.banner');

        $toggle = $banner->filter('button.banner-toggle');
        self::assertSame('disclosure', $banner->attr('data-controller'));
        self::assertSame('disclosure#toggle', $toggle->attr('data-action'));
        self::assertSame('false', $toggle->attr('aria-expanded'));
        self::assertSame('field-notes is paused', $toggle->text());
        self::assertCount(1, $toggle->filter('svg.chev'));
    }

    public function testOnlyTheCurrentSubTabIsMarked(): void
    {
        $tabs = $this->render('subtabs', ['label' => 'History', 'tabs' => [
            ['label' => 'Ledger', 'href' => '/history', 'current' => true],
            ['label' => 'Trends', 'href' => '/history/trends'],
        ]])->filter('nav.subtabs');

        self::assertSame('History', $tabs->attr('aria-label'));
        self::assertSame(['Ledger'], $tabs->filter('a[aria-current="page"]')->each(static fn (Crawler $tab): string => $tab->text()));
    }

    public function testAToastIsAnnouncedAndOffersUndoThroughAForm(): void
    {
        $toast = $this->render('toast', ['message' => 'All projects paused', 'undo_url' => '/projects/resume', 'undo_token' => 'token'])->filter('.toast');

        self::assertSame('status', $toast->attr('role'));
        self::assertSame('toast', $toast->attr('data-controller'));
        self::assertSame('All projects paused', $toast->filter('span')->text());
        self::assertSame('/projects/resume', $toast->filter('form')->attr('action'));
        self::assertSame('post', $toast->filter('form')->attr('method'));
        self::assertSame('token', $toast->filter('input[name="_token"]')->attr('value'));
        self::assertSame('Undo', $toast->filter('form button')->text());
    }

    public function testAToastWithoutUndoHasNoButton(): void
    {
        self::assertCount(0, $this->render('toast', ['message' => 'Test notification sent'])->filter('.toast button'));
    }

    public function testATermShowsItsOneStandardExplanation(): void
    {
        $term = $this->render('term', ['term' => 'version', 'words' => 'version'])->filter('.term');

        $tip = $term->filter('.term-tip');
        self::assertSame('0', $term->attr('tabindex'));
        self::assertSame(GlossaryTerm::Version->explanation(), $tip->text());
        self::assertSame('tooltip', $tip->attr('role'));
        self::assertSame($tip->attr('id'), $term->attr('aria-describedby'));
    }

    public function testATermWithoutAStandardExplanationFailsTheRender(): void
    {
        $this->expectException(RuntimeError::class);

        $this->render('term', ['term' => 'head', 'words' => 'head']);
    }

    public function testTheLoadingSkeletonIsStaticAndHiddenFromScreenReaders(): void
    {
        $skeleton = $this->render('skeleton', [])->filter('.skel-card');

        self::assertSame('true', $skeleton->attr('aria-hidden'));
        self::assertCount(3, $skeleton->filter('.skel'));
    }

    public function testTheErrorLineSaysWhatFailedAndWhatStillHolds(): void
    {
        $error = $this->render('error_line', ['failed' => "Couldn't connect.", 'text' => 'The vendor said the key is not valid. Nothing was saved.'])->filter('.test-result.err');

        self::assertSame('alert', $error->attr('role'));
        self::assertSame("Couldn't connect.", $error->filter('strong')->text());
        self::assertSame("Couldn't connect. The vendor said the key is not valid. Nothing was saved.", $error->text());
    }

    public function testAnEmptyStateSaysWhatHappensNext(): void
    {
        $empty = $this->render('empty', ['title' => 'Nothing needs you', 'text' => 'Intakt keeps building.'])->filter('.empty');

        self::assertSame('Nothing needs you', $empty->filter('h2')->text());
        self::assertSame('Intakt keeps building.', $empty->filter('p')->text());
    }

    public function testAnEmptyColumnSaysNothingHere(): void
    {
        self::assertSame('Nothing here', $this->render('column_empty', [])->filter('.col-empty')->text());
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $component, array $context): Crawler
    {
        return new Crawler($this->twig()->render('dashboard/components/'.$component.'.html.twig', $context));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function embed(string $component, array $context, string $block, string $content): Crawler
    {
        $source = sprintf("{%% embed 'dashboard/components/%s.html.twig' %%}{%% block %s %%}%s{%% endblock %%}{%% endembed %%}", $component, $block, $content);

        return new Crawler($this->twig()->createTemplate($source)->render($context));
    }

    private function twig(): Environment
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        return $twig;
    }

    /**
     * @return array<string, mixed>
     */
    private function card(): array
    {
        return [
            'href' => '/tasks/612',
            'number' => 612,
            'project' => 'intakt',
            'title' => 'Command overlay for tasks and settings',
            'meta' => [['icon' => 'clock', 'text' => '42 min'], ['text' => 'Slot 1, Model L'], ['text' => '9b3e5aa', 'mono' => true]],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function settingRow(array $context): Crawler
    {
        return $this->embed(
            'setting_row',
            [...$context, 'label' => 'Quiet hours', 'explanation' => 'No pushes in these hours.', 'control_id' => 'quiet-hours'],
            'control',
            '<input class="input" id="quiet-hours" name="quiet_hours">',
        )->filter('.srow');
    }

    /**
     * @param array{broken: bool} $context
     */
    private function banner(array $context): Crawler
    {
        return $this->embed(
            'banner',
            [...$context, 'icon' => 'pause', 'short' => 'field-notes is paused', 'title' => 'field-notes is paused.', 'text' => 'Nothing new starts.'],
            'action',
            '<button class="btn secondary sm">Resume</button>',
        );
    }
}
