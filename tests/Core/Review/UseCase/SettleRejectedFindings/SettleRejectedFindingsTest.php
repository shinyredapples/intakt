<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\SettleRejectedFindings;

use Intakt\Core\Memory\Contract\CodeSnapshot;
use Intakt\Core\Memory\Contract\MemoryIndex;
use Intakt\Core\Memory\Contract\ProjectMemoryNeedsEmbeddingModel;
use Intakt\Core\Memory\Contract\SettledFinding;
use Intakt\Core\Memory\Contract\SettledMemory;
use Intakt\Core\Memory\Contract\SettledQuery;
use Intakt\Core\Memory\Infrastructure\MemoryDocuments;
use Intakt\Core\Memory\Infrastructure\StoreMemoryIndex;
use Intakt\Core\Memory\Infrastructure\StoreProjectMemory;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\RoundJudged;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Infrastructure\CheckedOutFile;
use Intakt\Core\Review\Infrastructure\SettledCode;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\EscalateRecurringP1;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\EscalateRecurringP1Handler;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRoundHandler;
use Intakt\Core\Review\UseCase\JudgeRound\RecordRecurringP1s;
use Intakt\Core\Review\UseCase\SettleRejectedFindings\RejectedFindings;
use Intakt\Core\Review\UseCase\SettleRejectedFindings\SettleRejectedFinding;
use Intakt\Core\Review\UseCase\SettleRejectedFindings\SettleRejectedFindingHandler;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Core\Review\UseCase\EscalateRecurringP1\ScriptedEscalations;
use Intakt\Tests\Core\Review\UseCase\JudgeRound\ScriptedJudgement;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\VectorResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\AI\Store\Indexer\DocumentIndexer;
use Symfony\AI\Store\Indexer\DocumentProcessor;
use Symfony\AI\Store\InMemory\Store;
use Symfony\AI\Store\Retriever;
use Symfony\Component\Filesystem\Filesystem;

#[Group('persistence')]
#[CoversClass(RejectedFindings::class)]
#[CoversClass(SettleRejectedFinding::class)]
#[CoversClass(SettleRejectedFindingHandler::class)]
#[CoversClass(SettledCode::class)]
#[CoversClass(JudgeRoundHandler::class)]
#[CoversClass(EscalateRecurringP1Handler::class)]
#[CoversClass(RecordRecurringP1s::class)]
#[UsesClass(CheckedOutFile::class)]
#[UsesClass(CodeSnapshot::class)]
#[UsesClass(SettledFinding::class)]
#[UsesClass(SettledMemory::class)]
#[UsesClass(SettledQuery::class)]
#[UsesClass(StoreMemoryIndex::class)]
#[UsesClass(StoreProjectMemory::class)]
#[UsesClass(MemoryDocuments::class)]
final class SettleRejectedFindingsTest extends KernelTestCase
{
    private const string FILE = 'RecordingBus.php';
    private const string CHANGE = 'settled-change';
    private const string HEAD_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string HEAD_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private RoundFixture $fixture;
    private ?TemporaryRepository $checkout = null;

    public function testAP3TheJudgeRejectsIsQueuedAsSettledWithTheJudgesReasonAndTheHashOfItsFile(): void
    {
        $round = $this->roundReporting($this->p3(self::FILE));

        $this->judgedWith($round, new JudgedFinding('F1', Mark::Reject, null, 'A recording bus keeps every message on purpose.'));

        self::assertEquals([new SettleRejectedFinding('alpha', 'The bus keeps every message.', 'A recording bus keeps every message on purpose.', self::FILE, $this->hashOf(self::FILE))], $this->fixture->settlements->messages);
    }

    public function testAP3TheJudgeRejectsWithoutANoteIsSettledWithIntaktsOwnReason(): void
    {
        $round = $this->roundReporting($this->p3(self::FILE));

        $this->judgedWith($round, new JudgedFinding('F1', Mark::Reject));

        self::assertSame([RejectedFindings::JUDGE_GAVE_NO_REASON], array_column($this->fixture->settlements->messages, 'reason'));
    }

    public function testAP1TheJudgeMarksRejectStaysOnTheActListAndIsNotSettled(): void
    {
        $round = $this->roundReporting(new Finding('A', Severity::P1, self::FILE, 3, null, 'The bus loses a message.', FixableBy::Builder, false, null, null));

        $this->judgedWith($round, new JudgedFinding('F1', Mark::Reject, null, 'Not a bug.'));

        self::assertSame([], $this->fixture->settlements->messages);
    }

    public function testAP3TheJudgeMarksActOrDeferIsNotSettled(): void
    {
        $round = $this->roundReporting($this->p3(self::FILE), $this->p3('ScriptedVendor.php'));

        $this->judgedWith($round, new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Defer));

        self::assertSame([], $this->fixture->settlements->messages);
    }

    public function testARejectedFindingWithoutAFileOrWithAFileNotInTheCheckoutIsNotSettled(): void
    {
        $round = $this->roundReporting($this->p3(null), $this->p3('Gone.php'));

        $this->judgedWith($round, new JudgedFinding('F1', Mark::Reject), new JudgedFinding('F2', Mark::Reject));

        self::assertSame([], $this->fixture->settlements->messages);
    }

    public function testAHeldCredentialInTheJudgesReasonIsSettledRedacted(): void
    {
        $round = $this->roundReporting($this->p3(self::FILE));
        $secret = self::getContainer()->getParameter('kernel.secret');

        $this->judgedWith($round, new JudgedFinding('F1', Mark::Reject, null, 'Kept, the key '.$secret.' is a fixture.'));

        self::assertStringNotContainsString($secret, $this->fixture->settlements->messages[0]->reason);
    }

    public function testTheHashIsTakenBeforeTheRoundIsAnnouncedJudgedSoARemovedCheckoutStillSettles(): void
    {
        $this->checkout = new TemporaryRepository();
        $this->checkout->commitFile(self::FILE, "<?php\n// keeps every message\n");
        $root = $this->checkout->path;
        $this->fixture = new RoundFixture(self::getContainer(), checkoutRoot: $root);
        $this->fixture->events->addListener(RoundJudged::class, static function () use ($root): void {
            new Filesystem()->remove($root);
        });
        $round = $this->roundReporting($this->p3(self::FILE));

        $this->judgedWith($round, new JudgedFinding('F1', Mark::Reject, null, 'On purpose.'));

        self::assertSame([hash('sha256', "<?php\n// keeps every message\n")], array_column($this->fixture->settlements->messages, 'hash'));
    }

    public function testARejectingEscalationReviewIsQueuedAsSettledWithItsReasonsAndTheHashOfTheFileAsReviewed(): void
    {
        $this->recurredOnASecondHead();

        $this->fixture->escalate($this->fixture->escalations->messages[0], new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Rejected, 'The retry in send() delivers it.')));

        self::assertEquals([new SettleRejectedFinding('alpha', 'The bus loses a message.', 'The retry in send() delivers it.', self::FILE, $this->hashOf(self::FILE))], $this->fixture->settlements->messages);
    }

    public function testAConfirmingEscalationReviewIsNotSettled(): void
    {
        $this->recurredOnASecondHead();

        $this->fixture->escalate($this->fixture->escalations->messages[0], new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops it.')));

        self::assertSame([], $this->fixture->settlements->messages);
    }

    public function testAnEscalationQueuedWithoutACodeHashIsNotSettled(): void
    {
        $this->recurredOnASecondHead();
        $queued = $this->fixture->escalations->messages[0];

        $this->fixture->escalate(new EscalateRecurringP1($queued->round, $queued->finding, $queued->key), new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Rejected, 'The retry delivers it.')));

        self::assertSame([], $this->fixture->settlements->messages);
    }

    public function testAnEscalationSerializedBeforeTheCodeHashExistedIsReviewedAndNotSettled(): void
    {
        $this->recurredOnASecondHead();
        $queued = $this->fixture->escalations->messages[0];
        // Three properties and no codeHash, because PhpSerializer stored the message that way before the field existed.
        $message = unserialize(str_replace(['":4:{', 's:8:"codeHash";N;'], ['":3:{', ''], serialize(new EscalateRecurringP1($queued->round, $queued->finding, $queued->key))));
        self::assertInstanceOf(EscalateRecurringP1::class, $message);

        $this->fixture->escalate($message, new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Rejected, 'The retry delivers it.')));

        self::assertSame([[], 'rejected'], [$this->fixture->settlements->messages, $this->fixture->keptVerdicts()->ofKey($queued->key)?->outcome->value]);
    }

    public function testTheSettledFindingIsStoredInMemoryWithItsReasonAndCodeHash(): void
    {
        [$index, $memory] = $this->inMemory();
        $code = new CodeSnapshot(self::FILE, SettledCode::WHOLE_FILE, 'hash-1');

        new SettleRejectedFindingHandler($index, new SettledCode(), $this->fixture->log)(new SettleRejectedFinding('alpha', 'The bus keeps every message.', 'On purpose.', self::FILE, 'hash-1'));

        self::assertEquals([new SettledFinding('alpha', 'The bus keeps every message.', 'On purpose.', $code)], $memory->settledOn(new SettledQuery('alpha', $code))->settled);
    }

    public function testWithoutAnEmbeddingModelSettlingIsDoneWithOneInfoLine(): void
    {
        $index = self::createStub(MemoryIndex::class);
        $index->method('settle')->willThrowException(new ProjectMemoryNeedsEmbeddingModel());

        new SettleRejectedFindingHandler($index, new SettledCode(), $this->fixture->log)(new SettleRejectedFinding('alpha', 'Text.', 'Reason.', self::FILE, 'hash-1'));

        self::assertSame([['info', new ProjectMemoryNeedsEmbeddingModel()->getMessage(), ['project' => 'alpha']]], $this->fixture->log->cleanLogs());
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    protected function tearDown(): void
    {
        $this->checkout?->remove();
        parent::tearDown();
    }

    private function p3(?string $file): Finding
    {
        return new Finding('A', Severity::P3, $file, null === $file ? null : 3, null, 'The bus keeps every message.', FixableBy::Builder, false, null, null);
    }

    private function roundReporting(Finding ...$findings): string
    {
        $round = $this->fixture->start(1);
        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, array_values($findings), [], [])]));

        return $round->id()->toRfc4122();
    }

    private function judgedWith(string $round, JudgedFinding ...$marks): void
    {
        $this->fixture->judge(new ScriptedJudgement(new Judgement(array_values($marks))))(new JudgeRound($round));
    }

    private function hashOf(string $file): string
    {
        return (string) hash_file('sha256', __DIR__.'/../RunReviewRound/'.$file);
    }

    private function recurredOnASecondHead(): void
    {
        $keyed = new Finding('A', Severity::P1, self::FILE, 3, null, 'The bus loses a message.', FixableBy::Builder, false, 'Every message is delivered.', null);
        $this->completedAt(self::HEAD_A, $keyed);
        $this->completedAt(self::HEAD_B, new Finding('F1', Severity::P1, self::FILE, 3, null, $keyed->text, FixableBy::Builder, true, $keyed->requirement, null));
    }

    private function completedAt(string $head, Finding $finding): void
    {
        $round = $this->fixture->startWithDiffAtHead(self::CHANGE, $head, RoundFixture::DIFF_OF_RECORDING_BUS);
        $vendor = new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$finding], [], [])]);

        foreach ($this->fixture->bus->messages as $message) {
            if ($this->fixture->reviewer($message->reviewerId)->round()->id()->toRfc4122() === $round) {
                $this->fixture->deliver($message, $vendor);
            }
        }

        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))(new JudgeRound($round));
    }

    /**
     * @return array{StoreMemoryIndex, StoreProjectMemory}
     */
    private function inMemory(): array
    {
        $store = new Store();
        $platform = MockPlatformFactory::createPlatform(static fn (Model $model, array|string|object $input): VectorResult => new VectorResult(
            array_map(static fn (): Vector => new Vector([1.0, 0.0]), is_array($input) ? $input : [$input]),
        ));
        $vectorizer = new Vectorizer($platform, 'embedder');
        $documents = new MemoryDocuments();

        return [new StoreMemoryIndex(new DocumentIndexer(new DocumentProcessor($vectorizer, $store)), $vectorizer, $store, $documents), new StoreProjectMemory(new Retriever($store, $vectorizer), $documents)];
    }
}
