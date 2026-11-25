<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ChoosePanel;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\GlobCannotBeEvaluated;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelNotConfigured;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\PathGlob;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Infrastructure\ConfiguredPanels;
use Intakt\Core\Review\UseCase\ChoosePanel\ChoosePanelCommand;
use Intakt\Core\Review\UseCase\ChoosePanel\ChoosePanelInput;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ChoosePanelCommand::class)]
#[UsesClass(ChoosePanelInput::class)]
#[UsesClass(ConfiguredPanels::class)]
#[UsesClass(ChangeDescription::class)]
#[UsesClass(PathGlob::class)]
#[UsesClass(Panel::class)]
#[UsesClass(PanelReviewer::class)]
#[UsesClass(PanelNotConfigured::class)]
#[UsesClass(GlobCannotBeEvaluated::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Tier::class)]
#[UsesClass(QuorumPolicy::class)]
#[UsesClass(ReviewerRole::class)]
#[UsesClass(ReviewerModel::class)]
#[UsesClass(PanelVerdict::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Decision::class)]
final class ChoosePanelCommandTest extends TestCase
{
    private string|false $backtrackLimit;

    private string|false $jit;

    public function testItPrintsTheCorePanelForAChangeNoRuleMatches(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['project' => 'alpha', '--lines' => '5', '--path' => ['docs/a.md']]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('quorum all, 1 reviewers', $tester->getDisplay());
        self::assertStringContainsString('correctness: tier strong, provider anthropic, budget 3', $tester->getDisplay());
        self::assertStringNotContainsString('security', $tester->getDisplay());
    }

    public function testItAddsTheReviewersOfAMatchingRule(): void
    {
        $tester = $this->tester();

        $tester->execute(['project' => 'alpha', '--path' => ['src/Foo.php']]);

        self::assertStringContainsString('quorum all, 2 reviewers', $tester->getDisplay());
        self::assertStringContainsString('security: tier strong', $tester->getDisplay());
    }

    public function testTheFullReviewLabelIsReadFromTheLabelOption(): void
    {
        $tester = $this->tester();

        $tester->execute(['project' => 'alpha', '--label' => ['review:full']]);

        self::assertStringContainsString('2 reviewers', $tester->getDisplay());
    }

    public function testAnUnknownProjectFailsWithThePanelNotConfiguredMessage(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['project' => 'nowhere']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(new PanelNotConfigured('nowhere')->getMessage(), $tester->getDisplay());
    }

    public function testANegativeLinesOptionIsRefusedWithoutChoosingAPanel(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['project' => 'alpha', '--lines' => '-1']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('--lines must be zero or more', $tester->getDisplay());
        self::assertStringNotContainsString('quorum', $tester->getDisplay());
    }

    public function testAGlobThatCannotBeEvaluatedFailsTheCommandNamingTheGlob(): void
    {
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '500');
        $tester = $this->tester('**/a/**/a/**/c.php');

        $status = $tester->execute(['project' => 'alpha', '--path' => [str_repeat('a/', 60).'d.php']]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('**/a/**/a/**/c.php', $tester->getDisplay());
        self::assertStringNotContainsString('quorum', $tester->getDisplay());
    }

    public function testAnInvalidArgumentFromChoosingThePanelIsNotCaught(): void
    {
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willThrowException(new InvalidArgumentException('a programming error'));
        $application = new Application();
        $application->addCommand(new ChoosePanelCommand($panels));

        $this->expectExceptionObject(new InvalidArgumentException('a programming error'));

        new CommandTester($application->find('intakt:review:panel'))->execute(['project' => 'alpha']);
    }

    protected function setUp(): void
    {
        $this->backtrackLimit = ini_get('pcre.backtrack_limit');
        $this->jit = ini_get('pcre.jit');
    }

    protected function tearDown(): void
    {
        ini_set('pcre.backtrack_limit', (string) $this->backtrackLimit);
        ini_set('pcre.jit', (string) $this->jit);
    }

    private function tester(string $glob = 'src/**'): CommandTester
    {
        $panels = new ConfiguredPanels(['alpha' => [
            'quorum' => 'all',
            'reviewers' => [$this->reviewer('correctness')],
            'rules' => [['paths' => [$glob], 'reviewers' => [$this->reviewer('security')]]],
        ]], []);
        $application = new Application();
        $application->addCommand(new ChoosePanelCommand($panels));

        return new CommandTester($application->find('intakt:review:panel'));
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
}
