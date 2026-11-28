<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Contract;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FindingKind;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Kernel;
use Intakt\Tests\Core\Review\UseCase\JudgeRound\ScriptedJudgement;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

// Covers nothing because the contract is each finding's title and kind as an agent meets them through the running server, after a round the scripted judge answered.
#[Group('persistence')]
#[CoversNothing]
final class FindingTitleAndKindContractTest extends KernelTestCase
{
    private McpServerClient $client;

    private string $roundId;

    public function testAMergedActEntryCarriesTheTitleAndKindOfTheFindingThatSurvived(): void
    {
        $answer = $this->client->call('tools/call', ['name' => 'review_act_list', 'arguments' => ['roundId' => $this->roundId]], 3);

        $entry = McpServerClient::at($answer, 'result', 'structuredContent', 'act', '0');
        self::assertIsArray($entry);
        self::assertSame(['F1', ['F2'], 'A large cart loses its discount', 'logic'], [$entry['id'] ?? null, $entry['merged'] ?? null, $entry['title'] ?? null, $entry['kind'] ?? null]);
    }

    public function testTheVerdictsCarryEachFindingsTitleAndKindAsTheReviewerGaveThem(): void
    {
        $answer = $this->client->call('tools/call', ['name' => 'review_verdicts', 'arguments' => ['roundId' => $this->roundId]], 3);

        $findings = McpServerClient::at($answer, 'result', 'structuredContent', 'verdicts', '0', 'verdict', 'findings');
        self::assertIsArray($findings);
        self::assertSame([['A large cart loses its discount', 'logic'], ['The discount test is missing', 'missing test']], array_map(static fn (array $finding): array => [$finding['title'] ?? null, $finding['kind'] ?? null], $findings));
    }

    public function testTheFindingsResourceCarriesEachFindingsTitleAndKind(): void
    {
        $text = McpServerClient::at($this->client->call('resources/read', ['uri' => 'intakt://rounds/'.$this->roundId.'/findings'], 3), 'result', 'contents', '0', 'text');
        self::assertIsString($text);

        $findings = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($findings);
        self::assertSame(['A large cart loses its discount', 'The discount test is missing'], array_column($findings, 'title'));
        self::assertSame(['logic', 'missing test'], array_column($findings, 'kind'));
    }

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        self::assertInstanceOf(Kernel::class, $kernel);
        $fixture = new RoundFixture(self::getContainer());
        $round = $fixture->start(1);
        $this->roundId = $round->id()->toRfc4122();
        $findings = [
            new Finding('A', Severity::P2, 'src/Cart.php', 12, null, 'The total skips the discount.', FixableBy::Builder, false, null, null, title: 'A large cart loses its discount', kind: FindingKind::Logic),
            new Finding('B', Severity::P1, 'src/Cart.php', 12, null, 'The discount has no test.', FixableBy::Builder, false, null, null, title: 'The discount test is missing', kind: FindingKind::MissingTest),
        ];
        $fixture->deliver($fixture->bus->messages[0], new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, $findings, [], [])]));
        $fixture->judge(new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Act)])))(new JudgeRound($this->roundId));
        $this->client = new McpServerClient($kernel);
        $this->client->initialize('2025-11-25');
    }
}
