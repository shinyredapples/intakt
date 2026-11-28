<?php

declare(strict_types=1);

namespace Intakt\Tests\Probe;

use Intakt\Core\Memory\Contract\CodeSnapshot;
use Intakt\Core\Memory\Contract\SettledFinding;
use Intakt\Core\Memory\Contract\SettledMemory;
use Intakt\Core\Memory\Contract\SettledQuery;
use Intakt\Core\Memory\Infrastructure\EmbeddingsNotConfigured;
use Intakt\Core\Memory\Infrastructure\MemoryDocuments;
use Intakt\Core\Memory\Infrastructure\StoreMemoryIndex;
use Intakt\Core\Memory\Infrastructure\StoreProjectMemory;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Infrastructure\OptionalProjectMemory;
use Intakt\Core\Review\Infrastructure\ReviewerMemory;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\SettledCode;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\SettleRejectedFindings\RejectedFindings;
use Intakt\Core\Review\UseCase\SettleRejectedFindings\SettleRejectedFindingHandler;
use Intakt\Tests\Core\Review\UseCase\JudgeRound\ScriptedJudgement;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\VectorResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\UuidV7;

// Run through bin/probe, because the container's wiring from the judging to the next briefing is what the unit tests replace: a round whose P3 the scripted judge rejects, the settling through the container's handler into the configured SQLite memory file, and the next round's reviewer prompt from the container's ReviewerPrompt.
#[Group('persistence')]
#[CoversClass(RejectedFindings::class)]
#[CoversClass(SettleRejectedFindingHandler::class)]
#[CoversClass(ReviewerMemory::class)]
#[UsesClass(SettledCode::class)]
#[UsesClass(ReviewerPrompt::class)]
#[UsesClass(OptionalProjectMemory::class)]
#[UsesClass(StoreMemoryIndex::class)]
#[UsesClass(StoreProjectMemory::class)]
#[UsesClass(MemoryDocuments::class)]
#[UsesClass(SettledFinding::class)]
#[UsesClass(SettledQuery::class)]
#[UsesClass(SettledMemory::class)]
#[UsesClass(CodeSnapshot::class)]
final class SettledFindingBriefingTest extends KernelTestCase
{
    private const string FILE = 'src/Thing.php';

    private string $checkout;

    public function testTheNextRoundsReviewerIsBriefedOnTheFindingTheJudgeRejected(): void
    {
        $fixture = new RoundFixture(self::getContainer(), checkoutRoot: $this->checkout);
        $first = $this->roundReporting($fixture, new Finding('A', Severity::P3, self::FILE, 2, null, 'run() catches every Throwable.', FixableBy::Builder, false, null, null));

        $fixture->judge(new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Reject, null, 'The worker boundary must survive any failure.')])))(new JudgeRound($first));
        foreach ($fixture->settlements->messages as $settle) {
            self::getContainer()->get(SettleRejectedFindingHandler::class)($settle);
        }
        $second = $fixture->start(1);
        $briefing = $this->settledBriefingOf($second->project(), $second->diff(), new Checkout($second->checkoutRoot()));

        fwrite(STDOUT, PHP_EOL.'round 1 judged: F1 rejected; queued settle messages: '.count($fixture->settlements->messages).PHP_EOL.'round 2 reviewer briefing:'.PHP_EOL.$briefing.PHP_EOL);
        self::assertStringContainsString('already settled, do not raise again unless this code changed', $briefing);
        self::assertStringContainsString("src/Thing.php: run() catches every Throwable.\nReason: The worker boundary must survive any failure.", $briefing);
    }

    protected function setUp(): void
    {
        $memoryFile = self::bootKernel()->getCacheDir().'/memory.db';
        // Removed first, because the file outlives the kernel in the cache directory and rows of an earlier test would leak into this one.
        if (is_file($memoryFile)) {
            unlink($memoryFile);
        }

        // The probe runs offline and the instance has no embedding account, so the scripted platform stands in for the one the install would call.
        self::getContainer()->set(EmbeddingsNotConfigured::class, MockPlatformFactory::createPlatform(
            static fn (Model $model, array|string|object $input): VectorResult => new VectorResult(array_map(static fn (): Vector => new Vector([1.0, 0.0]), is_array($input) ? $input : [$input])),
        ));
        self::getContainer()->get('ai.store.sqlite.memory')->setup();
        // A plain directory, because the probe's sandbox runs no git and the reviewers read only the files.
        $this->checkout = sys_get_temp_dir().'/settled-briefing-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->checkout.'/'.self::FILE, "<?php\n// run() catches every Throwable on purpose\n");
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->checkout);
        parent::tearDown();
    }

    private function roundReporting(RoundFixture $fixture, Finding $finding): string
    {
        $round = $fixture->start(1);
        $fixture->deliver($fixture->bus->messages[0], new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$finding], [], [])]));

        return $round->id()->toRfc4122();
    }

    private function settledBriefingOf(string $project, string $diff, Checkout $checkout): string
    {
        $prompt = self::getContainer()->get(ReviewerPrompt::class);
        $messages = $prompt->messagesFor(new ReviewerConversation('You review.', new UuidV7()), new ReviewBrief(new ChangeUnderReview($project, 'Task.', $diff), [], 2), $checkout)->getMessages();
        $briefing = array_find($messages, static fn (object $message): bool => $message instanceof UserMessage && str_contains((string) $message->asText(), 'already settled'));
        self::assertInstanceOf(UserMessage::class, $briefing);

        return (string) $briefing->asText();
    }
}
