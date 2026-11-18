<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Contract\VerdictReceived;
use Intakt\Core\Review\Infrastructure\FencedToolbox;
use Intakt\Core\Review\Infrastructure\LiveProgress;
use Intakt\Core\Review\Infrastructure\Repository\ReadFile;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StopRequests;
use Intakt\Core\Review\Infrastructure\StreamingFallbacks;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRoundHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
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
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\Bridge\Anthropic\Factory;
use Symfony\AI\Platform\Bridge\Anthropic\ModelCatalog;
use Symfony\AI\Platform\Capability;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// Through the real agent, toolbox, worker and judge on the database, because a PNG's bytes in a tool answer cannot be sent to the provider as JSON.
#[Group('persistence')]
#[CoversClass(ReadFile::class)]
#[UsesClass(JudgeRoundHandler::class)]
#[UsesClass(ReviewWithReviewerHandler::class)]
#[UsesClass(ReviewerAgent::class)]
#[UsesClass(FencedToolbox::class)]
#[UsesClass(ReviewerToolboxFactory::class)]
#[UsesClass(ReviewerRuns::class)]
final class BinaryFileReadReviewTest extends KernelTestCase
{
    // The start of a real PNG, because it holds both a NUL byte and bytes that are not UTF-8.
    private const string PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x02\0\0\0";

    private RoundFixture $fixture;
    private TemporaryRepository $repository;

    /**
     * @var list<MockResponse>
     */
    private array $responses = [];

    public function testAReviewWhoseReviewerReadsAPngWithReadFileIsJudged(): void
    {
        $round = $this->fixture->start(1);
        $this->fixture->deliver($this->fixture->bus->messages[0], $this->reviewerReadingThePng());
        $judge = new ScriptedJudgement(new Judgement([]));

        $this->fixture->judge($judge)(new JudgeRound($round->id()->toRfc4122()));

        self::assertSame([Decision::Approve], $this->recordedDecisions());
    }

    public function testTheToolAnswerTheProviderGetsNamesThePngAsBinaryWithItsSize(): void
    {
        $this->fixture->start(1);

        $this->fixture->deliver($this->fixture->bus->messages[0], $this->reviewerReadingThePng());

        $request = $this->responses[1]->getRequestOptions()['body'];
        self::assertIsString($request);
        self::assertStringContainsString('Binary file, not shown: 29 bytes', $request);
        self::assertStringNotContainsString('IHDR', $request);
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('README.md', "base\n");
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('.evidence/shot.png', self::PNG);
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
        parent::tearDown();
    }

    // On the temporary repository's checkout, because the fixture's own checkout holds no binary file to read.
    private function reviewerReadingThePng(): AgentVendor
    {
        $this->responses = [
            RecordedAnthropicTurns::askingForATool()->thinking('I need the image.')->callingATool('toolu_01', 'read_file', ['path' => '.evidence/shot.png'])->asOneMessage(),
            RecordedAnthropicTurns::answering()->thinking('The image is read.')->saying('{"verdict":"approve","findings":[],"resolved":[],"follow_ups":[]}')->asOneMessage(),
        ];
        $catalog = new ModelCatalog(['reviewer-model' => ['class' => Claude::class, 'capabilities' => [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::OUTPUT_STRUCTURED, Capability::TOOL_CALLING, Capability::THINKING]]]);
        $platform = Factory::createPlatform('key', new MockHttpClient($this->responses), $catalog, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $runs = new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress(new RecordedRoundUpdates()), new ReviewerStopping(new StopRequests($this->fixture->ledger(), $this->fixture->ledgerPayloads())));
        $checkout = new Checkout($this->repository->path);
        $toolboxes = static fn (ReviewRequest $request): Toolbox => new ReviewerToolboxFactory()->inCheckout($checkout);
        $reviewer = new ReviewerAgent(new ReviewerPrompt(new Fence(), new PromptCut(new Fence()), new VerdictParser()), $toolboxes, $runs);

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
