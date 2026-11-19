<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Contract;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\UntrustedSource;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Kernel;
use Intakt\Tests\Core\Review\UseCase\JudgeRound\ScriptedJudgement;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

// Covers nothing because the contract is the act list answer as an agent meets it through the running server, after a round the scripted judge answered.
#[Group('persistence')]
#[CoversNothing]
final class ActListJudgeNoteContractTest extends KernelTestCase
{
    private McpServerClient $client;

    private string $roundId;

    public function testARejectedP1StaysOnTheActListWithTheJudgesNoteFenced(): void
    {
        $act = $this->actList();

        self::assertSame(['F1', 'F2'], array_column($act, 'id'));
        $note = McpServerClient::at($act, '0', 'judge_note');
        self::assertIsString($note);
        self::assertSame('The discount is applied in Checkout before the total is read.', new Fence()->contentOf(UntrustedSource::ActList, $note));
    }

    public function testAnEntryTheJudgeMarkedActHasNoNote(): void
    {
        $act = $this->actList();

        self::assertIsArray($act[1] ?? null);
        self::assertArrayNotHasKey('judge_note', $act[1]);
    }

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        self::assertInstanceOf(Kernel::class, $kernel);
        $fixture = new RoundFixture(self::getContainer());
        $round = $fixture->start(1);
        $this->roundId = $round->id()->toRfc4122();
        $findings = [
            new Finding('A', Severity::P1, 'src/Cart.php', 12, null, 'The total skips the discount.', FixableBy::Builder, false, null, null),
            new Finding('B', Severity::P2, 'src/Cart.php', 40, null, 'The rounding is untested.', FixableBy::Builder, false, null, null),
        ];
        $fixture->deliver($fixture->bus->messages[0], new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, $findings, [], [])]));
        $judgement = new Judgement([new JudgedFinding('F1', Mark::Reject, null, 'The discount is applied in Checkout before the total is read.'), new JudgedFinding('F2', Mark::Act)]);
        $fixture->judge(new ScriptedJudgement($judgement))(new JudgeRound($this->roundId));
        $this->client = new McpServerClient($kernel);
        $this->client->initialize('2025-11-25');
    }

    /**
     * @return array<mixed>
     */
    private function actList(): array
    {
        $answer = $this->client->call('tools/call', ['name' => 'review_act_list', 'arguments' => ['roundId' => $this->roundId]], 3);
        self::assertFalse(McpServerClient::at($answer, 'result', 'isError'));
        $act = McpServerClient::at($answer, 'result', 'structuredContent', 'act');
        self::assertIsArray($act);

        return $act;
    }
}
