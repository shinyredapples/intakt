<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\FollowUpNote;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Contract\VerdictUnreadable;
use Intakt\Core\Review\Infrastructure\AnthropicTextBlocks;
use Intakt\Core\Review\Infrastructure\FencedToolbox;
use Intakt\Core\Review\Infrastructure\LiveProgress;
use Intakt\Core\Review\Infrastructure\MalformedToolCalls;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StopRequests;
use Intakt\Core\Review\Infrastructure\StreamAcceptance;
use Intakt\Core\Review\Infrastructure\StreamedAnswer;
use Intakt\Core\Review\Infrastructure\StreamingFallbacks;
use Intakt\Core\Review\Infrastructure\StrictVerdictConverter;
use Intakt\Core\Review\Infrastructure\StrictVerdictStreamListener;
use Intakt\Core\Review\Infrastructure\StrictVerdictSubscriber;
use Intakt\Core\Review\Infrastructure\VerdictInText;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
use Intakt\Tests\Core\Review\Infrastructure\Repository\ReviewCheckout;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\StoredSerializerFactory;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\Bridge\Anthropic\Factory;
use Symfony\AI\Platform\Bridge\Anthropic\ModelCatalog;
use Symfony\AI\Platform\Capability;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(StrictVerdictSubscriber::class)]
#[CoversClass(StrictVerdictConverter::class)]
#[CoversClass(VerdictInText::class)]
#[CoversClass(StreamedAnswer::class)]
#[CoversClass(AnthropicTextBlocks::class)]
#[CoversClass(ReviewerPrompt::class)]
#[UsesClass(StrictVerdictStreamListener::class)]
#[UsesClass(ReviewerAgent::class)]
#[UsesClass(FencedToolbox::class)]
#[UsesClass(PromptCut::class)]
#[UsesClass(ReviewerRuns::class)]
#[UsesClass(LiveProgress::class)]
#[UsesClass(StreamAcceptance::class)]
#[UsesClass(MalformedToolCalls::class)]
#[UsesClass(ReviewerToolboxFactory::class)]
#[UsesClass(Fence::class)]
#[UsesClass(VerdictParser::class)]
#[UsesClass(VerdictUnreadable::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(ReviewRequest::class)]
#[UsesClass(ReviewBrief::class)]
#[UsesClass(ChangeUnderReview::class)]
#[UsesClass(ReviewerConversation::class)]
#[UsesClass(Checkout::class)]
final class AnthropicToolUsingReviewerTest extends WebTestCase
{
    private const string APPROVE = '{"verdict":"approve","findings":[],"resolved":[],"follow_ups":["Cover the empty cart."]}';

    private ReviewCheckout $fixture;

    public function testAReviewerOnAnthropicThatReadsAFileBeforeAnsweringHasItsFinalAnswerRecordedAsTheTypedVerdict(): void
    {
        $answer = RecordedAnthropicTurns::answering()->thinking('The cart is read, so the answer follows.')->saying(VerdictDocument::fromTheDesign()->with('resolved', [])->json());

        $verdict = $this->review($this->unstreamed($answer));

        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertSame('F3', $verdict->findings[0]->id);
    }

    public function testARecordedApprovingAnswerAfterAToolCallYieldsApproveWithItsFollowUps(): void
    {
        $verdict = $this->review($this->unstreamed(RecordedAnthropicTurns::answering()->thinking('Nothing is wrong.')->saying(self::APPROVE)));

        self::assertSame(Decision::Approve, $verdict->verdict, json_encode($verdict->findings, JSON_THROW_ON_ERROR));
        self::assertEquals([new FollowUpNote('Cover the empty cart.')], $verdict->follow_ups);
    }

    public function testAnAnswerAfterAToolCallThatMissesAVerdictFieldIsRefusedAsUnreadable(): void
    {
        $answer = RecordedAnthropicTurns::answering()->thinking('Done.')->saying('{"verdict":"approve","findings":[],"resolved":[]}');

        $this->expectException(VerdictUnreadable::class);

        $this->review($this->unstreamed($answer));
    }

    public function testAnAnswerWithThinkingButNoTextIsRefusedAsUnreadable(): void
    {
        $this->expectException(VerdictUnreadable::class);

        $this->review($this->unstreamed(RecordedAnthropicTurns::answering()->thinking('I have nothing to say.')));
    }

    public function testAnAnswerBesideItsThinkingThatBreaksAVerdictConstraintIsRefusedAsUnreadableSayingWhy(): void
    {
        $answer = RecordedAnthropicTurns::answering()->thinking('Done.')->saying(VerdictDocument::fromTheDesign()->with('resolved', [])->withFinding('id', '')->json());

        $this->expectException(VerdictUnreadable::class);
        $this->expectExceptionMessageMatches('/findings\[0\]\.id: This value should not be blank\.$/');

        $this->review($this->unstreamed($answer));
    }

    public function testTheTextAModelNarratesBeforeAToolCallIsNotTakenForTheAnswer(): void
    {
        $tool = RecordedAnthropicTurns::askingForATool()->thinking('I need the file.')->saying('I will read the cart first.')->callingATool('toolu_01', 'read_file', ['path' => 'src/Cart.php']);

        $verdict = $this->review($this->unstreamed(RecordedAnthropicTurns::answering()->saying(self::APPROVE), $tool));

        self::assertSame(Decision::Approve, $verdict->verdict, json_encode($verdict->findings, JSON_THROW_ON_ERROR));
    }

    public function testTheRequestAfterAToolCallReplaysTheThinkingWithItsSignatureAndLeavesOutTheNarration(): void
    {
        $tool = RecordedAnthropicTurns::askingForATool()->thinking('I need the file.')->saying('I will read the cart first.')->callingATool('toolu_01', 'read_file', ['path' => 'src/Cart.php']);
        $responses = $this->unstreamed(RecordedAnthropicTurns::answering()->saying(self::APPROVE), $tool);

        $this->review($responses);

        $request = $responses[1]->getRequestOptions()['body'];
        self::assertIsString($request);
        self::assertStringContainsString('"signature":"signature-0"', $request);
        self::assertStringContainsString('I need the file.', $request);
        self::assertStringNotContainsString('I will read the cart first.', $request);
    }

    public function testAStreamedReviewerOnAnthropicThatReadsAFileBeforeAnsweringHasItsFinalAnswerRecordedAsTheTypedVerdict(): void
    {
        $tool = RecordedAnthropicTurns::askingForATool()->callingATool('toolu_01', 'read_file', ['path' => 'src/Cart.php']);
        $answer = RecordedAnthropicTurns::answering()->thinking('Nothing is wrong.')->saying(self::APPROVE);

        $verdict = $this->review([$tool->asServerSentEvents(), $answer->asServerSentEvents()], new ModelCatalog());

        self::assertSame(Decision::Approve, $verdict->verdict, json_encode($verdict->findings, JSON_THROW_ON_ERROR));
        self::assertEquals([new FollowUpNote('Cover the empty cart.')], $verdict->follow_ups);
    }

    public function testTheTextAStreamedModelNarratesBeforeAToolCallIsNotTakenForTheAnswer(): void
    {
        $tool = RecordedAnthropicTurns::askingForATool()->thinking('Read first.')->saying('I will read the cart.')->callingATool('toolu_01', 'read_file', ['path' => 'src/Cart.php']);
        $answer = RecordedAnthropicTurns::answering()->thinking('Done.')->saying(self::APPROVE);

        $verdict = $this->review([$tool->asServerSentEvents(), $answer->asServerSentEvents()], new ModelCatalog());

        self::assertSame(Decision::Approve, $verdict->verdict, json_encode($verdict->findings, JSON_THROW_ON_ERROR));
        self::assertEquals([new FollowUpNote('Cover the empty cart.')], $verdict->follow_ups);
    }

    public function testAnAnswerInTwoTextBlocksBesideItsThinkingIsRefusedAsUnreadable(): void
    {
        $this->expectException(VerdictUnreadable::class);

        $this->review($this->unstreamed(RecordedAnthropicTurns::answering()->thinking('Done.')->saying(self::APPROVE)->saying(self::APPROVE)));
    }

    public function testAStreamedAnswerSplitAcrossTwoTextBlocksIsRefusedAsUnreadable(): void
    {
        $tool = RecordedAnthropicTurns::askingForATool()->callingATool('toolu_01', 'read_file', ['path' => 'src/Cart.php']);
        $answer = RecordedAnthropicTurns::answering()->thinking('Done.')->saying('{"verdict":"approve",')->saying('"findings":[],"resolved":[],"follow_ups":[]}');

        $this->expectException(VerdictUnreadable::class);

        $this->review([$tool->asServerSentEvents(), $answer->asServerSentEvents()], new ModelCatalog());
    }

    protected function setUp(): void
    {
        $this->fixture = new ReviewCheckout();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
        parent::tearDown();
    }

    /**
     * @return list<MockResponse>
     */
    private function unstreamed(RecordedAnthropicTurns $answer, ?RecordedAnthropicTurns $tool = null): array
    {
        $tool ??= RecordedAnthropicTurns::askingForATool()->callingATool('toolu_01', 'read_file', ['path' => 'src/Cart.php']);

        return [$tool->asOneMessage(), $answer->asOneMessage()];
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function review(array $responses, ?ModelCatalog $catalog = null): Verdict
    {
        // Without the streaming capability, because the reviewer streams whenever the catalog says the model can, and one answer in one piece is the other way a provider answers.
        $catalog ??= new ModelCatalog(['claude-sonnet-5-5' => ['class' => Claude::class, 'capabilities' => [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::OUTPUT_STRUCTURED, Capability::TOOL_CALLING, Capability::THINKING]]]);
        $platform = Factory::createPlatform('key', new MockHttpClient($responses), $catalog, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $ledger = new InMemoryLedger();
        $runs = new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks($ledger, new NullLogger()), new LiveProgress(new RecordedRoundUpdates()), new ReviewerStopping(new StopRequests($ledger, new LedgerPayloads(StoredSerializerFactory::create()))));
        $toolbox = new ReviewerToolboxFactory()->inCheckout($this->fixture->checkout);
        $reviewer = new ReviewerAgent(RemembersNothing::prompt(), static fn () => $toolbox, $runs);
        $brief = new ReviewBrief(new ChangeUnderReview('alpha', 'Add a cart.', 'diff --git a/a b/a'), [], 1);

        return $reviewer->review(new ReviewRequest($this->fixture->checkout, $brief, new ReviewerConversation('You review for correctness.', new UuidV7(), new UuidV7())), $platform, new ResolvedModel(Provider::Anthropic, 'claude-sonnet-5-5'));
    }
}
