<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\UseCase\CheckPullRequestText;

use Intakt\Core\HandBack\Contract\PullRequestTextOutcome;
use Intakt\Core\HandBack\Contract\PullRequestTextVerdict;
use Intakt\Core\HandBack\Domain\CodeSpans;
use Intakt\Core\HandBack\Domain\EvidenceItems;
use Intakt\Core\HandBack\Domain\FencedBlock;
use Intakt\Core\HandBack\Domain\HiddenBlockOpeners;
use Intakt\Core\HandBack\Domain\MarkdownPart;
use Intakt\Core\HandBack\Domain\MarkdownText;
use Intakt\Core\HandBack\Domain\OpenListItems;
use Intakt\Core\HandBack\Domain\ParagraphEnd;
use Intakt\Core\HandBack\Domain\PullRequestTextRule;
use Intakt\Core\HandBack\UseCase\CheckPullRequestText\FailedPullRequestText;
use Intakt\Core\HandBack\UseCase\CheckPullRequestText\PullRequestTextRoundNotRecorded;
use Intakt\Core\HandBack\UseCase\CheckPullRequestText\PullRequestTextRoute;
use Intakt\Core\HandBack\UseCase\CheckPullRequestText\PullRequestTextRoutes;
use Intakt\Core\HandBack\UseCase\CheckPullRequestText\PullRequestTextTask;
use Intakt\Core\HandBack\UseCase\CheckPullRequestText\RoutePullRequestText;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Tests\Core\HandBack\RecordingLedger;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(RoutePullRequestText::class)]
#[CoversClass(PullRequestTextRoutes::class)]
#[CoversClass(FailedPullRequestText::class)]
#[CoversClass(PullRequestTextRoute::class)]
#[CoversClass(PullRequestTextRoundNotRecorded::class)]
#[CoversClass(PullRequestTextTask::class)]
#[UsesClass(PullRequestTextRule::class)]
#[UsesClass(PullRequestTextOutcome::class)]
#[UsesClass(PullRequestTextVerdict::class)]
#[UsesClass(EvidenceItems::class)]
#[UsesClass(MarkdownText::class)]
#[UsesClass(MarkdownPart::class)]
#[UsesClass(CodeSpans::class)]
#[UsesClass(FencedBlock::class)]
#[UsesClass(HiddenBlockOpeners::class)]
#[UsesClass(OpenListItems::class)]
#[UsesClass(ParagraphEnd::class)]
final class RoutePullRequestTextTest extends TestCase
{
    private const string TASK = "Build it.\n\nEvidence:\n- E1. A red check.\n- E2. A green check.\n";

    private const string COMPLETE = "## Evidence\n- E1: red\n- E2: green\n";

    private const string MISSING_E2 = "## Evidence\n- E1: red\n";

    private RecordingLedger $ledger;

    public function testAFirstEvidenceMissIsSentBackToTheBuilder(): void
    {
        $result = $this->route(self::MISSING_E2);

        self::assertInstanceOf(FailedPullRequestText::class, $result);
        self::assertSame(PullRequestTextRoute::SentBack, $result->route);
        self::assertSame([2], $result->outcome->missingItems);
        self::assertSame([LedgerEventKind::PullRequestTextSentBack], $this->ledger->kinds());
        self::assertSame(['repository' => __DIR__, 'branch' => 'task', 'verdict' => 'missing-evidence-reference', 'reason' => PullRequestTextVerdict::MissingEvidenceReference->reason(), 'missing' => [2], 'body_characters' => 22], $this->ledger->lastPayload());
    }

    public function testASecondEvidenceMissInARowGoesToTheReviewers(): void
    {
        $this->route(self::MISSING_E2);
        $result = $this->route(self::MISSING_E2);

        self::assertSame(PullRequestTextRoute::ToReviewers, $result instanceof FailedPullRequestText ? $result->route : null);
        self::assertSame([LedgerEventKind::PullRequestTextSentBack, LedgerEventKind::PullRequestTextToReviewers], $this->ledger->kinds());
    }

    public function testABodyOverTheCapIsSentBackOnce(): void
    {
        $first = $this->route(str_repeat('a', PullRequestTextRule::MAX_BODY_CHARACTERS + 1));
        $second = $this->route(str_repeat('a', PullRequestTextRule::MAX_BODY_CHARACTERS + 1));

        self::assertEquals(PullRequestTextVerdict::BodyOverCap, $first instanceof FailedPullRequestText ? $first->outcome->verdict : null);
        self::assertSame([PullRequestTextRoute::SentBack, PullRequestTextRoute::ToReviewers], [$first instanceof FailedPullRequestText ? $first->route : null, $second instanceof FailedPullRequestText ? $second->route : null]);
    }

    public function testAPassingTextIsRecordedAndStartsTheCountAgain(): void
    {
        $this->route(self::MISSING_E2);
        $passed = $this->route(self::COMPLETE);
        $result = $this->route(self::MISSING_E2);

        self::assertInstanceOf(PullRequestTextOutcome::class, $passed);
        self::assertTrue($passed->passed());
        self::assertSame(PullRequestTextRoute::SentBack, $result instanceof FailedPullRequestText ? $result->route : null);
        self::assertSame([LedgerEventKind::PullRequestTextSentBack, LedgerEventKind::PullRequestTextPassed, LedgerEventKind::PullRequestTextSentBack], $this->ledger->kinds());
    }

    public function testAMissAfterAHandOffToTheReviewersIsSentBackAgain(): void
    {
        $this->route(self::MISSING_E2);
        $this->route(self::MISSING_E2);
        $result = $this->route(self::MISSING_E2);

        self::assertSame(PullRequestTextRoute::SentBack, $result instanceof FailedPullRequestText ? $result->route : null);
    }

    public function testAHandBackSendBackOfTheSameTaskDoesNotCountTowardTheTextRound(): void
    {
        $this->ledger->append(new LedgerEvent(LedgerEventKind::HandBackSentBack, json_encode(['repository' => __DIR__, 'branch' => 'task'], JSON_THROW_ON_ERROR)));

        $result = $this->route(self::MISSING_E2);

        self::assertSame(PullRequestTextRoute::SentBack, $result instanceof FailedPullRequestText ? $result->route : null);
    }

    public function testAnotherBranchsSendBackDoesNotCountForThisTask(): void
    {
        (void) $this->router()->route(PullRequestTextTask::resolved(__DIR__, 'other'), self::TASK, self::MISSING_E2);

        $result = $this->route(self::MISSING_E2);

        self::assertSame(PullRequestTextRoute::SentBack, $result instanceof FailedPullRequestText ? $result->route : null);
    }

    public function testTheSameRepositoryWrittenAnotherWayIsTheSameTask(): void
    {
        $this->route(self::MISSING_E2);

        $result = $this->router()->route(PullRequestTextTask::resolved(__DIR__.'/../CheckPullRequestText', 'task'), self::TASK, self::MISSING_E2);

        self::assertSame(PullRequestTextRoute::ToReviewers, $result instanceof FailedPullRequestText ? $result->route : null);
    }

    public function testALedgerThatRefusesTheLineIsARoundNotRecorded(): void
    {
        $this->ledger = new RecordingLedger(down: true);

        $this->expectException(PullRequestTextRoundNotRecorded::class);
        $this->expectExceptionMessage('the pull request text\'s pull_request_text_sent_back line was not recorded: the ledger is down');

        $this->route(self::MISSING_E2);
    }

    public function testNoLedgerLineHoldsTextOfThePullRequest(): void
    {
        $this->route("## Evidence\n- E1: the secret-looking word zebracorn\n");
        $this->route(self::COMPLETE.'and the word quokkafish');

        self::assertStringNotContainsString('zebracorn', $this->ledger->payloads());
        self::assertStringNotContainsString('quokkafish', $this->ledger->payloads());
    }

    public function testTheTasksLinesAreReadWhileItsLockIsHeld(): void
    {
        $store = new InMemoryStore();
        $key = 'intakt-pull-request-text-'.hash('sha256', json_encode([__DIR__, 'task'], JSON_THROW_ON_ERROR));
        $heldWhileRead = null;
        $this->ledger = new RecordingLedger(onRead: static function () use ($store, $key, &$heldWhileRead): void {
            $heldWhileRead = !new LockFactory($store)->createLock($key)->acquire();
        });

        (void) $this->router(new LockFactory($store))->route($this->task(), self::TASK, self::MISSING_E2);

        self::assertTrue($heldWhileRead);
        self::assertTrue(new LockFactory($store)->createLock($key)->acquire());
    }

    public function testTheTextRoundNeverWaitsOnTheHandBackLock(): void
    {
        $store = new InMemoryStore();
        $handBack = new LockFactory($store)->createLock('intakt-hand-back-'.hash('sha256', json_encode([__DIR__, 'task'], JSON_THROW_ON_ERROR)));
        $handBack->acquire();

        $result = $this->router(new LockFactory($store))->route($this->task(), self::TASK, self::MISSING_E2);

        self::assertInstanceOf(FailedPullRequestText::class, $result);
    }

    public function testARepositoryThatCannotBeFoundIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the repository "'.__DIR__.'/missing" cannot be found');

        PullRequestTextTask::resolved(__DIR__.'/missing', 'task');
    }

    public function testAnEmptyBranchIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the branch is empty');

        PullRequestTextTask::resolved(__DIR__, ' ');
    }

    protected function setUp(): void
    {
        $this->ledger = new RecordingLedger();
    }

    private function route(string $body): PullRequestTextOutcome|FailedPullRequestText
    {
        return $this->router()->route($this->task(), self::TASK, $body);
    }

    private function task(): PullRequestTextTask
    {
        return PullRequestTextTask::resolved(__DIR__, 'task');
    }

    private function router(LockFactory $locks = new LockFactory(new InMemoryStore())): RoutePullRequestText
    {
        return new RoutePullRequestText(new PullRequestTextRoutes($this->ledger, $locks));
    }
}
