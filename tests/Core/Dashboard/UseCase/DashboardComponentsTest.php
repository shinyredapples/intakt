<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\GlossaryTerm;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Markup;

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

    public function testAButtonWithoutAForcedStateRendersNoForcedStateClass(): void
    {
        $button = $this->render('button', ['kind' => 'primary', 'label' => 'Save'])->filter('button');

        self::assertSame('btn primary', $button->attr('class'));
    }

    public function testAButtonWithAForcedStateCarriesItsClass(): void
    {
        $button = $this->render('button', ['kind' => 'primary', 'label' => 'Save', 'forced' => 'hover'])->filter('button');

        self::assertSame('btn primary is-hover', $button->attr('class'));
    }

    public function testTheOtherComponentsCarryAForcedStateOnlyWhenGivenOne(): void
    {
        $plain = ['label' => 'Theme', 'icon' => 'moon', 'href' => '#', 'number' => 1, 'project' => 'p', 'title' => 'T', 'meta' => [], 'id' => 'x', 'name' => 'x'];

        foreach (['icon_button' => '.icon-btn', 'card' => '.card', 'input' => '.input'] as $component => $selector) {
            self::assertStringNotContainsString('is-', (string) $this->render($component, $plain)->filter($selector)->attr('class'), $component);
            self::assertStringContainsString('is-focus', (string) $this->render($component, $plain + ['forced' => 'focus'])->filter($selector)->attr('class'), $component);
        }
    }

    public function testASubTabWithAForcedStateCarriesItsClassAndTheOthersDoNot(): void
    {
        $tabs = $this->render('subtabs', ['label' => 'Example', 'tabs' => [['label' => 'Ledger', 'href' => '#'], ['label' => 'Focus', 'href' => '#', 'forced' => 'focus']]])->filter('a');

        self::assertNull($tabs->eq(0)->attr('class'));
        self::assertSame('is-focus', $tabs->eq(1)->attr('class'));
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
        self::assertSame('Click again to pause', $button->attr('data-arm-confirm'));
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

    /**
     * @return iterable<string, array{ReviewOutcome, string, string}>
     */
    public static function outcomes(): iterable
    {
        yield 'approved' => [ReviewOutcome::Approved, 'chip strong approved', 'm5 12.5'];
        yield 'changes requested' => [ReviewOutcome::ChangesRequested, 'chip strong', 'M4 12a8'];
        yield 'blocked' => [ReviewOutcome::Blocked, 'chip strong', 'rect x="6" y="6"'];
        yield 'failed' => [ReviewOutcome::Failed, 'chip broken', 'M12 7.5v5.5'];
    }

    #[DataProvider('outcomes')]
    public function testEachOutcomeChipShowsItsIconAndWordsGreenOnlyWhenApprovedAndRedOnlyWhenFailed(ReviewOutcome $outcome, string $class, string $drawing): void
    {
        $chip = $this->render('outcome_chip', ['outcome' => $outcome])->filter('.chip');

        self::assertSame($class, $chip->attr('class'));
        self::assertSame($outcome->value, $chip->text());
        self::assertStringContainsString($drawing, $chip->filter('svg.i.s')->html());
    }

    public function testAnOutcomeChipOnThePageCarriesTheOnPageClass(): void
    {
        $chip = $this->render('outcome_chip', ['outcome' => ReviewOutcome::Blocked, 'on_page' => true])->filter('.chip');

        self::assertSame('chip on-page strong', $chip->attr('class'));
    }

    public function testAnOutcomeChipRefusesAWordInsteadOfAnOutcome(): void
    {
        $this->expectException(RuntimeError::class);

        $this->render('outcome_chip', ['outcome' => 'Judged']);
    }

    public function testAReasonBadgeMayNameWhatItWaitsFor(): void
    {
        $badge = $this->render('reason_badge', ['reason' => 'dependency', 'label' => 'Needs #612'])->filter('.chip');

        self::assertSame('Needs #612', $badge->text());
    }

    public function testAReasonBadgeStaysNeutralWhenItsCallerHasADot(): void
    {
        $badge = $this->embed("{{ include('dashboard/components/reason_badge.html.twig', {reason: 'budget'}) }}", ['dot' => 'needs']);

        self::assertCount(0, $badge->filter('.dot'));
    }

    public function testAnIconInsideAComponentTakesNoClassFromItsCaller(): void
    {
        $chip = $this->embed("{{ include('dashboard/components/chip.html.twig', {icon: 'wallet', label: 'Budget'}) }}", ['extra' => 'needs']);

        self::assertSame('i s', $chip->filter('svg')->attr('class'));
    }

    public function testAnUnknownReasonFailsTheRender(): void
    {
        $this->expectException(RuntimeError::class);

        $this->render('reason_badge', ['reason' => 'weather']);
    }

    public function testAnUnknownReasonFailsTheRenderAlsoWithoutStrictVariables(): void
    {
        $this->twig()->disableStrictVariables();

        $this->expectException(RuntimeError::class);

        $this->render('reason_badge', ['reason' => 'weather']);
    }

    public function testAnUnknownIconFailsTheRenderAlsoWithoutStrictVariables(): void
    {
        $this->twig()->disableStrictVariables();

        $this->expectException(RuntimeError::class);

        $this->render('icon', ['name' => 'nope']);
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

    public function testAnElapsedTimeHoldsTheHiddenTimeOfADropForTheConnectionController(): void
    {
        $meta = $this->render('meta', ['facts' => [['icon' => 'clock', 'text' => '42 min', 'elapsed' => true], ['text' => 'Size M']]])->filter('.meta');

        $at = $meta->filter('span > .frozen-at');
        self::assertCount(1, $at);
        self::assertSame('frozen', $at->attr('data-connection-target'));
        self::assertNotNull($at->attr('hidden'));
        self::assertSame('', $at->text());
        self::assertSame(['42 min', 'Size M'], $meta->filter('.meta > span')->each(static fn (Crawler $fact): string => $fact->text()));
    }

    public function testAFrozenElapsedTimeShowsTheTimeOfTheDropAndIsLeftAloneByTheConnectionController(): void
    {
        $meta = $this->render('meta', ['facts' => [['icon' => 'clock', 'text' => '42 min', 'frozen_at' => '11:12']]])->filter('.meta');

        $at = $meta->filter('span > .frozen-at');
        self::assertSame('at 11:12', $at->text());
        self::assertNull($at->attr('hidden'));
        self::assertNull($at->attr('data-connection-target'));
    }

    public function testALabelFactShowsTheLabelAsOneWholeElementFollowedByTheMarkup(): void
    {
        $meta = $this->render('meta', ['facts' => [['label' => 'Cost:', 'markup' => new Markup('<span id="summary-cost">0.0050 USD estimated, prices of 2026-11-13</span>', 'UTF-8')]]])->filter('.meta');

        $label = $meta->filter('.meta > span > .fact-label');
        self::assertCount(1, $label);
        self::assertSame('Cost:', $label->text());
        self::assertCount(1, $meta->filter('.fact-label + span#summary-cost'));
        self::assertSame('Cost: 0.0050 USD estimated, prices of 2026-11-13', $meta->filter('.meta > span')->text(normalizeWhitespace: false));
    }

    public function testACardGivenAnOutcomeShowsItsChipOnItsOwnLineBetweenTheTopLineAndTheTitle(): void
    {
        $card = $this->render('card', [...$this->card(), 'outcome' => ReviewOutcome::ChangesRequested])->filter('a.card');

        $outcome = $card->filter('.top + .outcome');
        self::assertSame('Changes requested', $outcome->filter('.chip')->text());
        self::assertSame('chip strong', $outcome->filter('.chip')->attr('class'));
        self::assertCount(1, $card->filter('.outcome + .title'));
    }

    public function testACardWithoutAnOutcomeHasNoOutcomeLine(): void
    {
        $card = $this->render('card', [...$this->card(), 'outcome' => null])->filter('a.card');

        self::assertCount(0, $card->filter('.outcome'));
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
        $card = $this->render('card', [...$this->card(), 'ask' => 'Approve this version: secrets limit, ForgeTokenStore.php changed'])->filter('a.card');

        self::assertSame('card needs', $card->attr('class'));
        self::assertCount(1, $card->filter('.top .dot.needs'));
        self::assertSame('Approve this version: secrets limit, ForgeTokenStore.php changed', $card->filter('.title + p.blocker')->text());
    }

    public function testACardWithAnEmptyAskIsAPlainCard(): void
    {
        $card = $this->render('card', [...$this->card(), 'ask' => null])->filter('a.card');

        self::assertSame('card', $card->attr('class'));
        self::assertCount(0, $card->filter('.dot.needs, .blocker'));
    }

    public function testAListRowShowsTitleExplanationAndActionsOnTheRight(): void
    {
        $row = $this->embed(
            $this->filled('list_row', 'actions', '<button class="btn quiet sm">Remove</button>'),
            ['title' => 'Phone (home)', 'explanation' => 'Gets a push when something needs you.'],
        )->filter('.row');

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

    public function testASelectDoesNotChooseAnOptionWhoseValueOnlyEqualsTheSelectedOneAsANumber(): void
    {
        $select = $this->render('select', [
            'id' => 'project',
            'name' => 'project',
            'selected' => '1',
            'options' => [['value' => '01', 'label' => '01'], ['value' => '1', 'label' => '1'], ['value' => '1.0', 'label' => '1.0']],
        ])->filter('select.select');

        self::assertSame(['1'], $select->filter('option[selected]')->each(static fn (Crawler $option): string => $option->text()));
    }

    public function testASwitchIsACheckboxWithTheSwitchRole(): void
    {
        $input = $this->render('switch', ['id' => 'lan', 'name' => 'lan', 'on' => true])->filter('.switch input');

        self::assertSame('switch', $input->attr('role'));
        self::assertNotNull($input->attr('checked'));
    }

    public function testAStepperIsANamedGroupWithItsValueBetweenTwoNamedButtons(): void
    {
        $stepper = $this->render('stepper', ['label' => 'Parallel builds', 'value' => 2])->filter('.stepper');

        self::assertSame('group', $stepper->attr('role'));
        self::assertSame('Parallel builds', $stepper->attr('aria-label'));
        self::assertSame('2', $stepper->filter('button + output')->text());
        self::assertCount(1, $stepper->filter('output + button'));
        self::assertSame(['Decrease', 'Increase'], $stepper->filter('button[type="button"]')->each(static fn (Crawler $button): string => (string) $button->attr('aria-label')));
    }

    public function testACopyFieldShowsTheWholeCommandBesideACopyButton(): void
    {
        $copy = $this->render('copy', ['text' => 'intakt account login home'])->filter('.copy');

        self::assertSame('intakt account login home', $copy->filter('code')->text());
        self::assertSame('Copy', $copy->filter('code + button.btn.sm[type="button"]')->text());
    }

    public function testALastKnownBarIsDimmedAndSaysItIsTheLastKnownValue(): void
    {
        $bar = $this->render('bar', ['label' => 'Last known', 'note' => 'Unknown until logged in', 'used' => 22, 'last_known' => true])->filter('.bar');

        self::assertSame('bar last-known', $bar->attr('class'));
        self::assertSame('Last known: used 22%, reserved 0%, forecast 0%', $bar->attr('aria-label'));
    }

    public function testABarOfTheCurrentValueIsNotDimmed(): void
    {
        $bar = $this->render('bar', ['label' => 'This week', 'note' => 'Resets at 14:00', 'used' => 22])->filter('.bar');

        self::assertSame('bar', $bar->attr('class'));
        self::assertSame('Used 22%, reserved 0%, forecast 0%', $bar->attr('aria-label'));
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

    public function testABannerCollapsesOnPhonesBehindAToggleThatNeedsNoController(): void
    {
        $banner = $this->banner(['broken' => false])->filter('.banner');

        $toggle = $banner->filter(':scope > button.banner-toggle');
        self::assertNull($banner->attr('data-controller'));
        self::assertNull($toggle->attr('data-action'));
        self::assertSame('false', $toggle->attr('aria-expanded'));
        self::assertSame('field-notes is paused', $toggle->text());
        self::assertCount(1, $toggle->filter('svg.chev'));
    }

    public function testACompactBannerIsOneLineAtEveryWidthUntilItsToggleOpensIt(): void
    {
        $banner = $this->banner(['broken' => true, 'compact' => true])->filter('.banner');

        self::assertSame('banner broken compact', $banner->attr('class'));
        self::assertSame('false', $banner->filter('button.banner-toggle')->attr('aria-expanded'));
    }

    public function testAnOpenCompactBannerSaysItIsExpanded(): void
    {
        $banner = $this->banner(['broken' => false, 'compact' => true, 'open' => true])->filter('.banner');

        self::assertSame('banner compact open', $banner->attr('class'));
        self::assertSame('true', $banner->filter('button.banner-toggle')->attr('aria-expanded'));
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

    public function testSegmentedTabsAreANamedTabListWithOnlyTheSelectedTabMarked(): void
    {
        $tabs = $this->render('segmented', ['label' => 'Show', 'tabs' => [
            ['label' => 'Timeline', 'selected' => true],
            ['label' => 'Live log'],
        ]])->filter('.seg[role="tablist"]');

        self::assertSame('Show', $tabs->attr('aria-label'));
        self::assertSame(['true', 'false'], $tabs->filter('button[type="button"][role="tab"]')->each(static fn (Crawler $tab): string => (string) $tab->attr('aria-selected')));
        self::assertSame(['Timeline'], $tabs->filter('[aria-selected="true"]')->each(static fn (Crawler $tab): string => $tab->text()));
    }

    public function testAFilterChipSaysWhetherItIsPressed(): void
    {
        $chips = $this->embed(
            "{{ include('dashboard/components/filter_chip.html.twig', {label: '14 days', pressed: true}) }}{{ include('dashboard/components/filter_chip.html.twig', {label: '8 weeks'}) }}",
            [],
        )->filter('button.fchip[type="button"]');

        self::assertSame(['true', 'false'], $chips->each(static fn (Crawler $chip): string => (string) $chip->attr('aria-pressed')));
        self::assertSame(['14 days', '8 weeks'], $chips->each(static fn (Crawler $chip): string => $chip->text()));
    }

    public function testAnIconButtonIsNamedByItsLabelAndHidesItsIconFromScreenReaders(): void
    {
        $button = $this->render('icon_button', ['label' => 'Switch light or dark theme', 'icon' => 'moon'])->filter('button.icon-btn[type="button"]');

        self::assertSame('Switch light or dark theme', $button->attr('aria-label'));
        self::assertSame('', trim($button->text()));
        self::assertCount(1, $button->filter('svg.i[aria-hidden="true"]:not(.s) path'));
    }

    public function testAnIconButtonWithAnUnknownIconFailsTheRender(): void
    {
        $this->expectException(RuntimeError::class);

        $this->render('icon_button', ['label' => 'Nothing', 'icon' => 'nope']);
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

    public function testTheReconnectingLineSaysSinceWhenThePageShowsWhatIntaktKnewAndOffersTryNow(): void
    {
        $line = $this->render('connection', ['since' => '11:12:04'])->filter('.conn');

        self::assertSame('status', $line->attr('role'));
        self::assertNull($line->attr('hidden'));
        self::assertSame('true', $line->filter('.conn-dot')->attr('aria-hidden'));
        self::assertSame('Reconnecting.', $line->filter('strong')->text());
        self::assertSame('Reconnecting. The dashboard lost its connection to Intakt at 11:12:04. You are seeing what it knew then; actions wait until it is back. Next try in 5 s.', $line->filter('p')->text());
        self::assertSame('Try now', $line->filter('button.btn.secondary.sm[type="button"]')->text());
    }

    public function testTheReconnectingLineDoesNotAnnounceItsCountdown(): void
    {
        $countdown = $this->render('connection', ['since' => '11:12:04', 'next' => '3 s'])->filter('.conn [aria-live="off"]');

        self::assertSame('Next try in 3 s.', $countdown->text());
    }

    public function testAnUnreachableLineIsRedAndSaysIntaktCannotBeReached(): void
    {
        $line = $this->render('connection', ['since' => '11:12:04', 'unreachable' => true])->filter('.conn');

        self::assertSame('conn unreachable', $line->attr('class'));
        self::assertSame("Can't reach Intakt.", $line->filter('strong')->text());
    }

    public function testTheShellsReconnectingLineIsHiddenAndDrivenByTheConnectionController(): void
    {
        $line = $this->render('connection', ['live' => true])->filter('.conn');

        self::assertSame('', $line->attr('hidden'));
        self::assertSame('line', $line->attr('data-connection-target'));
        self::assertCount(1, $line->filter('strong[data-connection-target="title"]'));
        self::assertCount(1, $line->filter('[data-connection-target="since"]'));
        self::assertCount(1, $line->filter('[aria-live="off"] [data-connection-target="countdown"]'));
        self::assertSame('connection#tryNow', $line->filter('button')->attr('data-action'));
    }

    public function testAShownReconnectingLineIsLeftAloneByTheConnectionController(): void
    {
        $line = $this->render('connection', ['since' => '11:12:04']);

        self::assertCount(0, $line->filter('[data-connection-target], [data-action]'));
    }

    public function testTheOfflineMarkerSaysOffline(): void
    {
        $marker = $this->render('offline', [])->filter('.offline-marker');

        self::assertSame('Offline', $marker->text());
        self::assertNull($marker->attr('hidden'));
        self::assertNull($marker->attr('data-connection-target'));
    }

    public function testTheShellsOfflineMarkerIsHiddenAndDrivenByTheConnectionController(): void
    {
        $marker = $this->render('offline', ['live' => true])->filter('.offline-marker');

        self::assertSame('', $marker->attr('hidden'));
        self::assertSame('marker', $marker->attr('data-connection-target'));
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
        self::assertCount(0, $empty->filter('a'));
    }

    public function testAnEmptyStateWithAnActionOffersItAsItsOneLink(): void
    {
        $empty = $this->render('empty', ['title' => 'No projects yet', 'text' => 'No project yet.', 'action' => ['href' => '/setup', 'label' => 'Set up Intakt']])->filter('.empty');

        self::assertSame(['/setup'], $empty->filter('a')->each(static fn (Crawler $a): ?string => $a->attr('href')));
        self::assertSame('Set up Intakt', $empty->filter('a')->text());
    }

    public function testAnEmptyColumnSaysTheLineItsCallerGives(): void
    {
        self::assertSame('Tasks appear here once they are ready to plan', $this->render('column_empty', ['text' => 'Tasks appear here once they are ready to plan'])->filter('.col-empty')->text());
    }

    // Maintainer decision 5 of #2740: a column with no line of its own fails, because a fallback line tells a newcomer nothing.
    public function testAnEmptyColumnWithoutALineFailsToRender(): void
    {
        $this->expectException(RuntimeError::class);

        $this->render('column_empty', []);
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
    private function embed(string $source, array $context): Crawler
    {
        return new Crawler($this->twig()->createTemplate($source)->render($context));
    }

    private function filled(string $component, string $block, string $content): string
    {
        return sprintf("{%% embed 'dashboard/components/%s.html.twig' %%}{%% block %s %%}%s{%% endblock %%}{%% endembed %%}", $component, $block, $content);
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
            $this->filled('setting_row', 'control', '<input class="input" id="quiet-hours" name="quiet_hours">'),
            [...$context, 'label' => 'Quiet hours', 'explanation' => 'No pushes in these hours.', 'control_id' => 'quiet-hours'],
        )->filter('.srow');
    }

    /**
     * @param array{broken: bool, compact?: bool, open?: bool} $context
     */
    private function banner(array $context): Crawler
    {
        return $this->embed(
            $this->filled('banner', 'action', '<button class="btn secondary sm">Resume</button>'),
            [...$context, 'icon' => 'pause', 'short' => 'field-notes is paused', 'title' => 'field-notes is paused.', 'text' => 'Nothing new starts.'],
        );
    }
}
