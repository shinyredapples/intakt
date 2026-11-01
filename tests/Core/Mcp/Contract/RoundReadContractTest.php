<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Contract;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
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
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

// Covers nothing because the contract is the read tools and the findings resource as an agent meets them through the running server.
#[Group('persistence')]
#[CoversNothing]
final class RoundReadContractTest extends KernelTestCase
{
    private OwnerServerClient $client;

    private string $roundId;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        self::assertInstanceOf(Kernel::class, $kernel);
        $fixture = new RoundFixture(self::getContainer());
        $round = $fixture->start(2);
        $this->roundId = $round->id()->toRfc4122();
        $findings = [new Finding('A', Severity::P1, null, null, null, 'Text of A.', FixableBy::Builder, false, null, null)];

        foreach ($fixture->bus->messages as $message) {
            $fixture->deliver($message, new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, $findings, [], [])]));
        }

        $fixture->judge(new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Act)])))(new JudgeRound($this->roundId));
        $this->client = new OwnerServerClient($kernel);
        $this->client->initialize('2025-11-25');
    }

    public function testEveryReadToolIsListedReadOnlyAndNotDestructive(): void
    {
        $tools = OwnerServerClient::at($this->client->call('tools/list', [], 2), 'result', 'tools');
        self::assertIsArray($tools);

        foreach (['review_status', 'review_verdicts', 'review_act_list'] as $name) {
            $tool = array_first(array_filter($tools, static fn (mixed $listed): bool => is_array($listed) && $name === ($listed['name'] ?? null)));
            self::assertIsArray($tool, $name.' is not listed.');
            self::assertTrue(OwnerServerClient::at($tool, 'annotations', 'readOnlyHint'), $name);
            self::assertFalse(OwnerServerClient::at($tool, 'annotations', 'destructiveHint'), $name);
        }
    }

    public function testTheStatusToolAnswersWithTheRoundsStateAndReviewers(): void
    {
        $result = $this->callTool('review_status');

        self::assertFalse(OwnerServerClient::at($result, 'isError'));
        self::assertSame('judged', OwnerServerClient::at($result, 'structuredContent', 'state'));
        $reviewers = OwnerServerClient::at($result, 'structuredContent', 'reviewers');
        self::assertIsArray($reviewers);
        self::assertCount(2, $reviewers);
    }

    public function testTheVerdictsToolAnswersWithTheReviewersVerdicts(): void
    {
        $result = $this->callTool('review_verdicts');

        self::assertFalse(OwnerServerClient::at($result, 'isError'));
        self::assertSame('changes', OwnerServerClient::at($result, 'structuredContent', 'verdicts', '0', 'verdict', 'verdict'));
    }

    public function testTheActListToolAnswersWithTheFindingsTheJudgeMarkedAct(): void
    {
        $result = $this->callTool('review_act_list');

        $act = OwnerServerClient::at($result, 'structuredContent', 'act');
        self::assertFalse(OwnerServerClient::at($result, 'isError'));
        self::assertTrue(OwnerServerClient::at($result, 'structuredContent', 'judged'));
        self::assertIsArray($act);
        self::assertSame(['F1'], array_column($act, 'id'));
    }

    public function testAnUnknownRoundIsAnMcpToolErrorWithoutAStackTrace(): void
    {
        $answer = $this->client->call('tools/call', ['name' => 'review_status', 'arguments' => ['roundId' => 'not-a-round']], 3);

        self::assertTrue(OwnerServerClient::at($answer, 'result', 'isError'));
        self::assertStringContainsString('No review round is stored under the id not-a-round', $this->client->lastBody());
        self::assertStringNotContainsString('#0', $this->client->lastBody());
    }

    public function testTheFindingsResourceTemplateIsListed(): void
    {
        $templates = OwnerServerClient::at($this->client->call('resources/templates/list', [], 3), 'result', 'resourceTemplates');

        self::assertIsArray($templates);
        self::assertSame(['intakt://rounds/{roundId}/findings'], array_column($templates, 'uriTemplate'));
    }

    public function testTheFindingsResourceAnswersWithTheRoundsReportedFindings(): void
    {
        $answer = $this->client->call('resources/read', ['uri' => 'intakt://rounds/'.$this->roundId.'/findings'], 3);

        $text = OwnerServerClient::at($answer, 'result', 'contents', '0', 'text');
        self::assertSame('application/json', OwnerServerClient::at($answer, 'result', 'contents', '0', 'mimeType'));
        self::assertIsString($text);
        $findings = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($findings);
        self::assertSame(['F1'], array_column($findings, 'id'));
    }

    public function testAnUnknownRoundResourceIsAnMcpErrorWithoutAStackTrace(): void
    {
        $answer = $this->client->call('resources/read', ['uri' => 'intakt://rounds/not-a-round/findings'], 3);

        self::assertIsInt(OwnerServerClient::at($answer, 'error', 'code'));
        self::assertStringNotContainsString('#0', $this->client->lastBody());
    }

    /**
     * @return array<mixed>
     */
    private function callTool(string $name): array
    {
        $answer = $this->client->call('tools/call', ['name' => $name, 'arguments' => ['roundId' => $this->roundId]], 3);
        $result = OwnerServerClient::at($answer, 'result');
        self::assertIsArray($result);

        return $result;
    }
}
