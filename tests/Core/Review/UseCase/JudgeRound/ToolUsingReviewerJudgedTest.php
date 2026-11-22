<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Contract\VerdictReceived;
use Intakt\Core\Review\Infrastructure\FencedToolbox;
use Intakt\Core\Review\Infrastructure\LiveProgress;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StopRequests;
use Intakt\Core\Review\Infrastructure\StreamingFallbacks;
use Intakt\Core\Review\Infrastructure\StrictVerdictConverter;
use Intakt\Core\Review\Infrastructure\StrictVerdictSubscriber;
use Intakt\Core\Review\UseCase\JudgeRound\JudgedRounds;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRoundHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
use Intakt\Tests\Core\Review\Infrastructure\RecordedAnthropicTurns;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\AgentVendor;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\Bridge\Anthropic\Factory;
use Symfony\AI\Platform\Bridge\Anthropic\ModelCatalog;
use Symfony\AI\Platform\Capability;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// Replays recorded answers through the real agent, toolbox and worker up to the judge, because a stubbed verdict hid that a tool-using reviewer's findings never reached it.
#[Group('persistence')]
#[CoversClass(JudgeRoundHandler::class)]
#[CoversClass(JudgedRounds::class)]
#[CoversClass(StoredFindings::class)]
#[CoversClass(ReviewWithReviewerHandler::class)]
#[CoversClass(StrictVerdictSubscriber::class)]
#[CoversClass(StrictVerdictConverter::class)]
#[UsesClass(ReviewerAgent::class)]
#[UsesClass(FencedToolbox::class)]
#[UsesClass(ReviewerToolboxFactory::class)]
#[UsesClass(ReviewerRuns::class)]
#[UsesClass(RoundLedger::class)]
final class ToolUsingReviewerJudgedTest extends KernelTestCase
{
    private const string FINDING = 'The cart drops its last item.';
    private const string SECOND_FINDING = 'The cart total ignores the discount.';

    private RoundFixture $fixture;

    /**
     * @var list<MockResponse>
     */
    private array $responses = [];

    public function testTheFindingsOfAChangesAnswerAfterAToolCallReachTheJudge(): void
    {
        $round = $this->fixture->start(1);
        $document = VerdictDocument::fromTheDesign()->with('resolved', [])->withFinding('text', self::FINDING);
        $answer = $document->decoded();
        $answer['findings'] = [$document->finding(), [...$document->finding(), 'id' => 'F4', 'line' => 30, 'text' => self::SECOND_FINDING]];
        $this->fixture->deliver($this->fixture->bus->messages[0], $this->reviewerAnswering(json_encode($answer, JSON_THROW_ON_ERROR)));
        $judge = new ScriptedJudgement(new Judgement([]));

        $this->fixture->judge($judge)(new JudgeRound($round->id()->toRfc4122()));

        $asked = $judge->asked;
        self::assertInstanceOf(JudgeRequest::class, $asked);
        self::assertSame([['F1', 'P1', self::FINDING], ['F2', 'P1', self::SECOND_FINDING]], array_map(static fn (Finding $finding): array => [$finding->id, $finding->severity->value, $finding->text], $asked->findings));
        self::assertSame([Decision::Changes], $this->recordedDecisions());
    }

    public function testTheAnswerFollowsARequestThatCarriesTheFileTheToolRead(): void
    {
        $this->fixture->start(1);
        $vendor = $this->reviewerAnswering('{"verdict":"approve","findings":[],"resolved":[],"follow_ups":[]}');

        $this->fixture->deliver($this->fixture->bus->messages[0], $vendor);

        $request = $this->responses[1]->getRequestOptions()['body'];
        self::assertIsString($request);
        self::assertStringContainsString('"tool_use_id":"toolu_01"', $request);
        self::assertStringContainsString('final readonly class RoundFixture', $request);
    }

    public function testAnApprovingAnswerAfterAToolCallCountsAsApproveAndAsksTheJudgeNothing(): void
    {
        $round = $this->fixture->start(1);
        $this->fixture->deliver($this->fixture->bus->messages[0], $this->reviewerAnswering('{"verdict":"approve","findings":[],"resolved":[],"follow_ups":[]}'));
        $judge = new ScriptedJudgement(new Judgement([]));

        $this->fixture->judge($judge)(new JudgeRound($round->id()->toRfc4122()));

        self::assertSame([Decision::Approve], $this->recordedDecisions());
        self::assertSame(0, $judge->calls);
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    // The tool reads a file of the fixture's checkout, so the second request carries a real tool result.
    private function reviewerAnswering(string $answer): AgentVendor
    {
        $this->responses = [
            RecordedAnthropicTurns::askingForATool()->thinking('I need the fixture.')->callingATool('toolu_01', 'read_file', ['path' => 'RoundFixture.php'])->asOneMessage(),
            RecordedAnthropicTurns::answering()->thinking('The fixture is read.')->saying($answer)->asOneMessage(),
        ];
        $catalog = new ModelCatalog(['reviewer-model' => ['class' => Claude::class, 'capabilities' => [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::OUTPUT_STRUCTURED, Capability::TOOL_CALLING, Capability::THINKING]]]);
        $platform = Factory::createPlatform('key', new MockHttpClient($this->responses), $catalog, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $runs = new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress(new RecordedRoundUpdates()), new ReviewerStopping(new StopRequests($this->fixture->ledger(), $this->fixture->ledgerPayloads())));
        $reviewer = new ReviewerAgent(new ReviewerPrompt(new Fence(), new PromptCut(new Fence()), new VerdictParser()), new ReviewerToolboxFactory()->create(...), $runs);

        return new AgentVendor($reviewer, $platform);
    }

    /**
     * @return list<?Decision>
     */
    private function recordedDecisions(): array
    {
        $payloads = $this->fixture->ledgerPayloads();

        return array_values(array_map(static fn (RecordedEvent $recorded): ?Decision => $payloads->of($recorded, VerdictReceived::class)?->decision, iterator_to_array($this->fixture->ledger()->readOfKind(LedgerEventKind::VerdictReceived))));
    }
}
