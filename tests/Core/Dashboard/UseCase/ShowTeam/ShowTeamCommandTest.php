<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowTeam;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\AccountCredentials;
use Intakt\Core\Ai\Contract\ListedAccounts;
use Intakt\Core\Ai\Contract\ProjectNames;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Budget\Contract\AccountPick;
use Intakt\Core\Budget\Contract\LeftOutAccount;
use Intakt\Core\Budget\Contract\LeftOutCause;
use Intakt\Core\Budget\Contract\RankedAccounts;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\BuildRouting;
use Intakt\Core\Build\Contract\TierChoice;
use Intakt\Core\Build\Contract\TierReason;
use Intakt\Core\Dashboard\UseCase\ShowTeam\ShowTeamCommand;
use Intakt\Core\Dashboard\UseCase\ShowTeam\Team;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamAccount;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamRole;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamRoster;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\PanelNotConfigured;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Tests\Core\Budget\PicksInMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ShowTeamCommand::class)]
#[CoversClass(TeamRole::class)]
#[UsesClass(TeamRoster::class)]
#[UsesClass(Team::class)]
#[UsesClass(TeamAccount::class)]
#[UsesClass(Account::class)]
#[UsesClass(TierChoice::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(ProjectNames::class)]
#[UsesClass(BuildFacts::class)]
#[UsesClass(Paths::class)]
#[UsesClass(ChangeDescription::class)]
#[UsesClass(Labels::class)]
#[UsesClass(AccountPick::class)]
#[UsesClass(RankedAccounts::class)]
#[UsesClass(LeftOutAccount::class)]
final class ShowTeamCommandTest extends TestCase
{
    public function testTheCommandPrintsEveryAccountWithItsStateAndRoles(): void
    {
        $tester = $this->printed([Provider::Anthropic->value => ['default', 'team'], Provider::ClaudeCode->value => ['default']]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(
            "anthropic.default: ready: Developer (the build runtime cannot run it)\nanthropic.team: ready: Developer (the build runtime cannot run it)\nclaude_code.default: ready: Developer\n",
            $tester->getDisplay(),
        );
    }

    public function testAnInstallWithoutAccountsSaysSo(): void
    {
        self::assertSame("No account is listed.\n", $this->printed([])->getDisplay());
    }

    public function testTheLastPickNamesThePickedAccountAndEachLeftOutAccountWithItsCauseAndReset(): void
    {
        $picks = new PicksInMemory();
        $picks->record(Provider::ClaudeCode, new RankedAccounts([new Account(Provider::ClaudeCode, 'team')], [new LeftOutAccount(new Account(Provider::ClaudeCode, 'default'), LeftOutCause::AtWeeklyCeiling, new DateTimeImmutable('2026-11-30T12:00:00+00:00'))]));

        self::assertStringEndsWith(
            "Last pick for claude_code at 2026-11-28 12:00 UTC: picked claude_code.team; left out: claude_code.default (weekly-ceiling until 2026-11-30 12:00 UTC)\n",
            $this->printed([Provider::ClaudeCode->value => ['default', 'team']], $picks)->getDisplay(),
        );
    }

    public function testAStartThatFoundNoAccountNamesTheEarliestReset(): void
    {
        $picks = new PicksInMemory();
        $picks->record(Provider::ClaudeCode, new RankedAccounts([], [
            new LeftOutAccount(new Account(Provider::ClaudeCode, 'default'), LeftOutCause::AtWeeklyCeiling, new DateTimeImmutable('2026-11-30T12:00:00+00:00')),
            new LeftOutAccount(new Account(Provider::ClaudeCode, 'team'), LeftOutCause::AtShortWindowCeiling, new DateTimeImmutable('2026-11-28T15:00:00+00:00')),
        ]));

        self::assertStringEndsWith(
            "Last pick for claude_code at 2026-11-28 12:00 UTC: no account could start, earliest reset 2026-11-28 15:00 UTC; left out: claude_code.default (weekly-ceiling until 2026-11-30 12:00 UTC), claude_code.team (short-window-ceiling until 2026-11-28 15:00 UTC)\n",
            $this->printed([Provider::ClaudeCode->value => ['default', 'team']], $picks)->getDisplay(),
        );
    }

    public function testAStartThatFoundNoAccountWithNoKnownResetSaysTheResetIsUnknown(): void
    {
        $picks = new PicksInMemory();
        $picks->record(Provider::ClaudeCode, new RankedAccounts([], [new LeftOutAccount(new Account(Provider::ClaudeCode, 'default'), LeftOutCause::AtWeeklyCeiling, null)]));

        self::assertStringEndsWith(
            "no account could start, earliest reset unknown; left out: claude_code.default (weekly-ceiling until unknown)\n",
            $this->printed([Provider::ClaudeCode->value => ['default']], $picks)->getDisplay(),
        );
    }

    public function testAPickWithNoLeftOutAccountSaysNone(): void
    {
        $picks = new PicksInMemory();
        $picks->record(Provider::ClaudeCode, new RankedAccounts([new Account(Provider::ClaudeCode, 'default')], []));

        self::assertStringEndsWith("picked claude_code.default; left out: none\n", $this->printed([Provider::ClaudeCode->value => ['default']], $picks)->getDisplay());
    }

    public function testAnAccountWithoutARoleSaysSo(): void
    {
        self::assertSame("codex.default: ready: no role\n", $this->printed([Provider::Codex->value => ['default']])->getDisplay());
    }

    public function testAnAccountNameHoldingConsoleMarkupIsPrintedAsConfigured(): void
    {
        self::assertStringContainsString('codex.<error>x</error>: ', $this->printed([Provider::Codex->value => ['<error>x</error>']])->getDisplay());
    }

    /**
     * @param array<string, list<string>> $accounts
     */
    private function printed(array $accounts, PicksInMemory $picks = new PicksInMemory()): CommandTester
    {
        $listed = self::createStub(ListedAccounts::class);
        $listed->method('of')->willReturnCallback(static fn (Provider $provider): array => array_map(static fn (string $name): Account => new Account($provider, $name), $accounts[$provider->value] ?? []));
        $credentials = self::createStub(AccountCredentials::class);
        $credentials->method('areSet')->willReturn(true);
        $routing = self::createStub(BuildRouting::class);
        $routing->method('tierFor')->willReturnCallback(static fn (string $project, BuildFacts $facts): TierChoice => EscalationOutcome::Confirmed === $facts->escalation ? new TierChoice(Tier::Strong, TierReason::ConfirmedEscalation) : new TierChoice(Tier::Standard, TierReason::Default));
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('projects')->willReturn(new ProjectNames('alpha'));
        $tiers->method('configured')->willReturnCallback(static fn (string $project, Tier $tier): ResolvedModel => new ResolvedModel(Tier::Strong === $tier ? Provider::Anthropic : Provider::ClaudeCode, 'model'));
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willThrowException(new PanelNotConfigured('alpha'));

        $tester = new CommandTester(new Command()->setCode(new ShowTeamCommand(new TeamRoster($listed, $credentials, self::createStub(AccountCooldowns::class), $routing, $tiers, $panels, $picks))));
        $tester->execute([]);

        return $tester;
    }
}
