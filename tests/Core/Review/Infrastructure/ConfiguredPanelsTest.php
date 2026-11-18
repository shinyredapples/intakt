<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelNotConfigured;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\PathGlob;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Infrastructure\ConfiguredPanels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfiguredPanels::class)]
#[UsesClass(Panel::class)]
#[UsesClass(ChangeDescription::class)]
#[UsesClass(Paths::class)]
#[UsesClass(Labels::class)]
#[UsesClass(PathGlob::class)]
#[UsesClass(PanelReviewer::class)]
#[UsesClass(PanelNotConfigured::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Tier::class)]
#[UsesClass(QuorumPolicy::class)]
#[UsesClass(ReviewerRole::class)]
#[UsesClass(ReviewerModel::class)]
#[UsesClass(PanelVerdict::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Decision::class)]
final class ConfiguredPanelsTest extends TestCase
{
    public function testThePanelOfAProjectCarriesItsReviewersInConfigOrderAndItsPolicy(): void
    {
        $panels = new ConfiguredPanels(['alpha' => [
            'quorum' => 'majority',
            'reviewers' => [
                ['role' => 'correctness', 'tier' => 'strong', 'provider' => 'anthropic', 'prompt' => 'Logic.', 'budget' => 5],
                ['role' => 'style', 'tier' => 'light', 'provider' => 'openai', 'prompt' => 'Style.', 'budget' => 2],
            ],
        ]]);

        $panel = $panels->panelFor('alpha');

        self::assertSame(QuorumPolicy::Majority, $panel->quorum);
        self::assertSame(['correctness', 'style'], array_map(static fn (PanelReviewer $reviewer): string => $reviewer->role->name, $panel->reviewers));
        self::assertSame(Tier::Light, $panel->reviewers[1]->model->tier);
        self::assertSame(Provider::OpenAi, $panel->reviewers[1]->model->provider);
        self::assertSame('Style.', $panel->reviewers[1]->role->prompt);
        self::assertSame(2, $panel->reviewers[1]->budget);
    }

    public function testAProjectWithoutAPanelIsRefused(): void
    {
        $this->expectException(PanelNotConfigured::class);
        $this->expectExceptionObject(new PanelNotConfigured('beta'));

        new ConfiguredPanels([])->panelFor('beta');
    }

    public function testAChangeBelowTheLineThresholdKeepsTheCorePanel(): void
    {
        $panel = $this->ruled()->panelForChange('alpha', new ChangeDescription(99, new Paths(), new Labels()));

        self::assertSame(['correctness'], $this->roles($panel));
    }

    public function testAChangeAtTheLineThresholdAddsTheRuleReviewers(): void
    {
        $panel = $this->ruled()->panelForChange('alpha', new ChangeDescription(100, new Paths(), new Labels()));

        self::assertSame(['correctness', 'big'], $this->roles($panel));
    }

    public function testAChangeToAMatchingPathAddsTheRuleReviewers(): void
    {
        $panel = $this->ruled()->panelForChange('alpha', new ChangeDescription(1, new Paths('docs/a.md', 'config/security/rules/x.yaml'), new Labels()));

        self::assertSame(['correctness', 'security'], $this->roles($panel));
    }

    public function testAPathGlobWithOneStarDoesNotReachIntoSubfolders(): void
    {
        $panel = $this->ruled()->panelForChange('alpha', new ChangeDescription(1, new Paths('docs/a/b.md'), new Labels()));

        self::assertSame(['correctness'], $this->roles($panel));
    }

    public function testAChangedPathThatIsNotValidUtf8StillMatchesItsGlob(): void
    {
        $panel = $this->ruled()->panelForChange('alpha', new ChangeDescription(1, new Paths("config/security/\xFF.php"), new Labels()));

        self::assertSame(['correctness', 'security'], $this->roles($panel));
    }

    public function testAChangedPathWithANewlineStillMatchesADoubleStarGlob(): void
    {
        $panel = $this->ruled()->panelForChange('alpha', new ChangeDescription(1, new Paths("config/security/a\nb.php"), new Labels()));

        self::assertSame(['correctness', 'security'], $this->roles($panel));
    }

    public function testADoubleStarBetweenSlashesMatchesNoFolderAtAll(): void
    {
        $panel = $this->nested()->panelForChange('alpha', new ChangeDescription(1, new Paths('src/Security/Door.php'), new Labels()));

        self::assertSame(['correctness', 'security'], $this->roles($panel));
    }

    public function testADoubleStarBetweenSlashesMatchesNestedFoldersWithANewline(): void
    {
        $panel = $this->nested()->panelForChange('alpha', new ChangeDescription(1, new Paths("src/x\ny/Security/a.php"), new Labels()));

        self::assertSame(['correctness', 'security'], $this->roles($panel));
    }

    public function testADoubleStarBetweenSlashesDoesNotMatchAFolderThatOnlyEndsInTheName(): void
    {
        $panel = $this->nested()->panelForChange('alpha', new ChangeDescription(1, new Paths('src/NoSecurity/Door.php'), new Labels()));

        self::assertSame(['correctness'], $this->roles($panel));
    }

    public function testALeadingDoubleStarMatchesAFolderAtTheRoot(): void
    {
        $panel = $this->anywhere()->panelForChange('alpha', new ChangeDescription(1, new Paths('Security/Door.php'), new Labels()));

        self::assertSame(['correctness', 'security'], $this->roles($panel));
    }

    public function testALeadingDoubleStarMatchesANestedFolder(): void
    {
        $panel = $this->anywhere()->panelForChange('alpha', new ChangeDescription(1, new Paths('a/Security/Door.php'), new Labels()));

        self::assertSame(['correctness', 'security'], $this->roles($panel));
    }

    public function testALeadingDoubleStarDoesNotMatchAFolderThatOnlyEndsInTheName(): void
    {
        $panel = $this->anywhere()->panelForChange('alpha', new ChangeDescription(1, new Paths('NoSecurity/Door.php'), new Labels()));

        self::assertSame(['correctness'], $this->roles($panel));
    }

    public function testConsecutiveDoubleStarsBetweenSlashesMatchNoFolderAtAll(): void
    {
        $panel = $this->securityRule('src/**/**/Security/**')->panelForChange('alpha', new ChangeDescription(1, new Paths('src/Security/Door.php'), new Labels()));

        self::assertSame(['correctness', 'security'], $this->roles($panel));
    }

    public function testConsecutiveDoubleStarsBetweenSlashesMatchANestedFolder(): void
    {
        $panel = $this->securityRule('src/**/**/Security/**')->panelForChange('alpha', new ChangeDescription(1, new Paths('src/a/Security/Door.php'), new Labels()));

        self::assertSame(['correctness', 'security'], $this->roles($panel));
    }

    public function testConsecutiveLeadingDoubleStarsMatchAFolderAtTheRoot(): void
    {
        $panel = $this->securityRule('**/**/Security/**')->panelForChange('alpha', new ChangeDescription(1, new Paths('Security/Door.php'), new Labels()));

        self::assertSame(['correctness', 'security'], $this->roles($panel));
    }

    public function testConsecutiveLeadingDoubleStarsDoNotMatchAFolderThatOnlyEndsInTheName(): void
    {
        $panel = $this->securityRule('**/**/Security/**')->panelForChange('alpha', new ChangeDescription(1, new Paths('NoSecurity/Door.php'), new Labels()));

        self::assertSame(['correctness'], $this->roles($panel));
    }

    public function testAOneCharacterWildcardMatchesOneCharacterOfAUtf8Path(): void
    {
        $panel = new ConfiguredPanels(['alpha' => [
            'quorum' => 'all',
            'reviewers' => [$this->reviewer('correctness')],
            'rules' => [['paths' => ['src/?.php'], 'reviewers' => [$this->reviewer('short')]]],
        ]])->panelForChange('alpha', new ChangeDescription(1, new Paths('src/é.php'), new Labels()));

        self::assertSame(['correctness', 'short'], $this->roles($panel));
    }

    public function testAChangeToNoMatchingPathKeepsTheCorePanelAndItsQuorum(): void
    {
        $panel = $this->ruled()->panelForChange('alpha', new ChangeDescription(1, new Paths('src/a.php'), new Labels()));

        self::assertSame(['correctness'], $this->roles($panel));
        self::assertSame(QuorumPolicy::All, $panel->quorum);
    }

    public function testTheFullReviewLabelAddsTheReviewersOfEveryRule(): void
    {
        $panel = $this->ruled()->panelForChange('alpha', new ChangeDescription(1, new Paths(), new Labels('review:full')));

        self::assertSame(['correctness', 'big', 'security'], $this->roles($panel));
    }

    public function testAnotherLabelAddsNothing(): void
    {
        $panel = $this->ruled()->panelForChange('alpha', new ChangeDescription(1, new Paths(), new Labels('review:security')));

        self::assertSame(['correctness'], $this->roles($panel));
    }

    private function ruled(): ConfiguredPanels
    {
        return new ConfiguredPanels(['alpha' => [
            'quorum' => 'all',
            'reviewers' => [$this->reviewer('correctness')],
            'rules' => [
                ['min_lines' => 100, 'reviewers' => [$this->reviewer('big')]],
                ['paths' => ['config/security/**', 'docs/*.md'], 'reviewers' => [$this->reviewer('security')]],
            ],
        ]]);
    }

    private function nested(): ConfiguredPanels
    {
        return $this->securityRule('src/**/Security/**');
    }

    private function anywhere(): ConfiguredPanels
    {
        return $this->securityRule('**/Security/**');
    }

    private function securityRule(string $glob): ConfiguredPanels
    {
        return new ConfiguredPanels(['alpha' => [
            'quorum' => 'all',
            'reviewers' => [$this->reviewer('correctness')],
            'rules' => [['paths' => [$glob], 'reviewers' => [$this->reviewer('security')]]],
        ]]);
    }

    /**
     * @param non-empty-string $role
     *
     * @return array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}
     */
    private function reviewer(string $role): array
    {
        return ['role' => $role, 'tier' => 'strong', 'provider' => 'anthropic', 'prompt' => 'Look.', 'budget' => 3];
    }

    /**
     * @return list<string>
     */
    private function roles(Panel $panel): array
    {
        return array_map(static fn (PanelReviewer $reviewer): string => $reviewer->role->name, $panel->reviewers);
    }
}
