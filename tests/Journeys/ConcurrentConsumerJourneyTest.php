<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Dashboard\UseCase\StopReview\ReviewerStops;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\StartReview;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewer;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleProject;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleRepository;
use Intakt\Tests\Journeys\Support\CallRecords;
use Intakt\Tests\Journeys\Support\ConsumerProcess;
use Intakt\Tests\Journeys\Support\InstanceDatabase;
use Intakt\Tests\Journeys\Support\JourneyTestCase;
use Intakt\Tests\Journeys\Support\MonotonicSeconds;
use Intakt\Tests\Journeys\Support\ReviewServer;
use Intakt\Tests\Journeys\Support\ScriptDirectory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\SemaphoreInterface;

// Over the shipped reviewer, its account limit and its stop check, answered by a scripted Anthropic API, because part 2 of the 8.2 adoption starts the container's consumer as one `messenger:consume async --concurrency` process only if these cases hold.
#[CoversNothing]
#[Group('journeys')]
final class ConcurrentConsumerJourneyTest extends JourneyTestCase
{
    // Not a credential, because the scripted API ignores it; the seed only checks that a key is present.
    private const string PLACEHOLDER_KEY = 'journey-placeholder';
    private const string TRANSPORT = 'async';
    private const int CONCURRENCY = 2;
    // Docker's default grace time, because `docker stop` kills the container once it has passed.
    private const float GRACE_SECONDS = 10.0;
    private const float WAIT_SECONDS = 40.0;
    // What docker/entrypoint.sh waits after its SIGTERM before it kills what still runs, so a consumer still running then is killed inside Docker's 10 seconds.
    private const float ENTRYPOINT_WAIT_SECONDS = 8.0;
    private const int ACCOUNT_CAP = 3;
    // Far above what the consumer itself uses, so only a worker that made a call crosses it.
    private const int MEMORY_LIMIT_MB = 128;
    private const int WORKER_WEIGHT_MB = 160;
    private const string QUEUE_VARIABLE = 'MESSENGER_TRANSPORT_DSN';
    private const string SHARED_QUEUE = 'doctrine://default';

    private string $directory = '';

    private int $round = 0;

    private string $roundId = '';

    private string $script = '';

    /** @var list<SemaphoreInterface> */
    private array $held = [];

    private ?ReviewServer $server = null;

    /** @var list<ConsumerProcess> */
    private array $consumers = [];

    protected function tearDown(): void
    {
        foreach ($this->consumers as $consumer) {
            $consumer->kill();
        }

        foreach (CallRecords::in($this->script)->processes() as $worker) {
            posix_kill($worker, SIGKILL);
        }

        $this->server?->stop();

        $this->releaseAccountPlaces();

        if ('' !== $this->directory) {
            new Filesystem()->remove($this->directory);
        }
    }

    protected function journey(): string
    {
        return 'concurrent consumer';
    }

    public function testOneConcurrentConsumerRunsReviewersTogetherHoldsTheAccountCapStopsAReviewerEndsOnSigtermAndIsReplacedAtItsLimit(): void
    {
        $this->step('create the database', fn () => self::assertStringContainsString('[OK]', $this->console(['doctrine:migrations:migrate'])));
        $this->step('seed the sample project', $this->seedTheSampleProject(...));
        $this->step('start the dashboard the reviewers read the change through', fn () => $this->server = ReviewServer::start());
        $this->step('start one consumer with --concurrency', $this->startTheConsumer(...));
        $this->step('run the first two reviewers one after the other, because the first message of a class runs alone', $this->runTheFirstRoundCold(...));
        $this->step('run two reviewers of one round at the same time', $this->runTwoReviewersTogether(...));
        $this->step('refuse a call over the account cap and start it once a place is free', $this->holdTheAccountCap(...));
        $this->step('cancel a running reviewer on a stop request', $this->stopARunningReviewer(...));
        $this->step('end the idle consumer on SIGTERM', fn () => $this->endGracefully($this->consumer()));
        $this->step('replace a consumer at its message limit, and read the message its default fetch size leaves behind (does not hold)', $this->replaceAConsumerAtItsMessageLimit(...));
        $this->step('replace a parallel worker past the memory limit while the consumer goes on', $this->replaceAWorkerPastTheMemoryLimit(...));
        $this->step('read what SIGTERM during a review leaves behind (does not end within the entrypoint wait)', $this->readSigtermDuringAReview(...));
    }

    private function seedTheSampleProject(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-journey-'.bin2hex(random_bytes(6));
        $this->rebuildContainer($this->environment());
        $output = $this->console(['intakt:sample:seed', $this->directory.'/sample', $this->configuration()], $this->environment());

        self::assertStringContainsString('Built the sample repository', $output);
        // Again after the seed, because the sample project lives in the compiled container.
        $this->rebuildContainer($this->environment());
        $this->script = $this->directory.'/script';
        mkdir($this->script, 0o777, true);
    }

    private function startTheConsumer(): void
    {
        $this->consume(['--concurrency='.self::CONCURRENCY, '--time-limit=200']);
    }

    // A consumer learns from the first message of a class whether its handler batches, and runs that message alone until then (ParallelExecutionStrategy::shouldPauseConsumption()), so a fresh consumer never overlaps the first two reviewers of a round.
    private function runTheFirstRoundCold(): void
    {
        $this->arm(hold: false);
        $this->openRound();
        $this->waitForJudge();
        $calls = CallRecords::in($this->script);

        self::assertSame([2, 2, 1], [$calls->started(), $calls->ended(), $calls->mostAtOnce()]);
    }

    // Held until the second reviewer has started, so the first cannot end before the second began: the overlap is forced, not hoped for.
    private function runTwoReviewersTogether(): void
    {
        $this->arm(hold: true);
        $this->openRound();
        $this->waitUntil(fn (): bool => 2 === CallRecords::in($this->script)->started(), 'the two reviewers did not both start: '.$this->consumer()->output());
        $this->releaseOneByOne();
        $calls = CallRecords::in($this->script);

        self::assertSame([2, 2, 2], [$calls->started(), $calls->ended(), $calls->mostAtOnce()]);
        self::assertCount(2, $calls->processes());
        self::assertNotContains($this->consumer()->pid(), $calls->processes());
        $this->waitUntil(fn (): bool => 0 === $this->database()->queued(ReviewWithReviewer::class), 'a reviewer message stayed on the transport');
    }

    // Two of the account's three places are taken by the test before the round opens, so the two reviewers share the last one.
    private function holdTheAccountCap(): void
    {
        $this->arm(hold: true);
        $this->takeAccountPlaces(self::ACCOUNT_CAP - 1);
        $this->openRound();

        // Both, because the refused reviewer can be sent for a retry before the held one's call started; the held one keeps its place until released, so the refused one cannot start meanwhile.
        $this->waitUntil(fn (): bool => $this->consumer()->hasPrinted('retry') && 1 <= CallRecords::in($this->script)->started(), 'the call over the cap was not sent for a retry: '.$this->consumer()->output());
        self::assertSame(1, CallRecords::in($this->script)->started(), 'a call started over the account cap');
        new ScriptDirectory($this->script)->releaseAll();
        $this->waitForJudge();
        $this->releaseAccountPlaces();
        $calls = CallRecords::in($this->script);

        self::assertSame([2, 2, 1], [$calls->started(), $calls->ended(), $calls->mostAtOnce()]);
        self::assertGreaterThanOrEqual($calls->calls[0]['end'], $calls->calls[1]['start']);
    }

    private function stopARunningReviewer(): void
    {
        $this->arm(hold: true);
        $stopped = $this->database()->ledgerRows('reviewer_stopped');
        $this->openRound();

        $this->waitUntil(fn (): bool => 2 === CallRecords::in($this->script)->started(), 'the two reviewers did not both start: '.$this->consumer()->output());
        $reviewer = $this->database()->payloads('reviewer_started', 1)[0]['reviewer'] ?? null;
        $flash = $this->serviceWith(ReviewerStops::class, $this->environment())->requestStopOf($this->roundId, is_string($reviewer) ? $reviewer : self::fail('no reviewer started'));

        self::assertSame(['success', 'Asked the reviewer to stop.'], $flash);
        $this->releaseOneByOne();

        self::assertSame($stopped + 1, $this->database()->ledgerRows('reviewer_stopped'));
    }

    // A consumer at its limit handles the message it received and ends with status 0, so the next consumer the entrypoint starts goes on. Observed, not wished for: it fetches --fetch-size messages at once, by default its --concurrency, and leaves the ones it had not handled marked as delivered, which the Doctrine transport hands out again only after redeliver_timeout (90 minutes); with --fetch-size=1 it leaves none.
    private function replaceAConsumerAtItsMessageLimit(): void
    {
        $this->arm(hold: false);
        $this->openRound(queueReviewers: true);
        $waiting = $this->database()->waiting();

        $this->consumeToTheLimit([]);

        self::assertSame($waiting - 1, $this->database()->waiting(), 'the consumer did not handle exactly one message');
        self::assertSame(1, $this->database()->fetchedNotAcknowledged(ReviewWithReviewer::class), 'the default fetch size left no fetched message behind');
        // As the transport's redelivery time would, so the replacement can fetch it.
        $this->database()->makeFetchedMessagesAvailable();

        $this->consumeToTheLimit(['--fetch-size=1']);

        self::assertSame($waiting - 2, $this->database()->waiting(), 'the replacement did not handle exactly one message');
        self::assertSame(0, $this->database()->fetchedNotAcknowledged(ReviewWithReviewer::class), 'a fetch size of one left a fetched message behind');
        self::assertSame(1, CallRecords::in($this->script)->ended());
    }

    // A parallel worker checks the memory limit after each message, ends past it, and the consumer starts another in its place (ParallelExecutionStrategy::replaceChannel()); the consumer itself stays below the limit and goes on. Each call leaves more than the limit in its worker, so a worker never makes a second call.
    private function replaceAWorkerPastTheMemoryLimit(): void
    {
        $this->arm(
            hold: false,
            settled: false,
        );
        file_put_contents($this->script.'/'.ScriptDirectory::WEIGHT, (string) self::WORKER_WEIGHT_MB);
        $consumer = $this->consume(['--concurrency='.self::CONCURRENCY, '--memory-limit='.self::MEMORY_LIMIT_MB.'M', '--time-limit=200']);

        // The last reviewer the limit step left comes first, so its round is judged before the next one opens.
        $this->waitForJudge();
        $this->openRound();
        $this->waitForJudge();
        $calls = CallRecords::in($this->script);

        self::assertSame([3, 3], [$calls->started(), $calls->ended()]);
        self::assertCount(3, $calls->processes(), 'a worker past the memory limit made another call');
        self::assertTrue($consumer->running(), 'the consumer ended at the memory limit of its workers: '.$consumer->output());
        $this->endGracefully($consumer);
    }

    // Observed, not wished for: the consumer keeps running after SIGTERM until its review ends, as a consumer without --concurrency does, so the entrypoint's kill ends it within the grace time; the interrupted message stays on the transport, and its worker process outlives the killed consumer (inside a container it ends with the container).
    private function readSigtermDuringAReview(): void
    {
        $this->arm(hold: true);
        $this->openRound(queueReviewers: true);
        $consumer = $this->consume(['--concurrency='.self::CONCURRENCY, '--time-limit=60']);

        $this->waitUntil(fn (): bool => 1 <= CallRecords::in($this->script)->started(), 'no reviewer started: '.$consumer->output());
        $signalled = $consumer->terminate();

        self::assertNull($consumer->endedWithin(self::ENTRYPOINT_WAIT_SECONDS, $signalled), 'the consumer ended on SIGTERM during a review');
        self::assertFalse($consumer->running());
        self::assertSame(2, $this->database()->queued(ReviewWithReviewer::class), 'an interrupted message left the transport');
        self::assertNotSame([], array_filter(CallRecords::in($this->script)->processes(), static fn (int $pid): bool => posix_kill($pid, 0)), 'no worker outlived the killed consumer');
    }

    private function openRound(bool $queueReviewers = false): void
    {
        ++$this->round;
        $bus = $this->serviceWith(MessageBusInterface::class, $this->environment());
        $requested = new RequestedRound(SampleRepository::BRANCH, $this->round, new ChangeSource($this->directory.'/sample', SampleRepository::BASE));
        $id = $bus->dispatch(new StartReview(SampleProject::NAME, SampleProject::TASK, $requested))->last(HandledStamp::class)?->getResult();
        $this->roundId = is_string($id) ? $id : throw new RuntimeException('The review start returned no round id.');

        if (!$queueReviewers) {
            return;
        }

        // One message at a time, without the script, until both reviewers wait on the transport, so no reviewer runs before its case.
        for ($step = 0; $step < 8 && 2 > $this->database()->queued(ReviewWithReviewer::class); ++$step) {
            $this->console(['messenger:consume', self::TRANSPORT, '--limit=1', '--time-limit=5'], [self::QUEUE_VARIABLE => self::SHARED_QUEUE, ...$this->environment()]);
        }

        self::assertSame(2, $this->database()->queued(ReviewWithReviewer::class));
    }

    // Fresh for each case, so a case never counts the calls of the one before.
    private function arm(bool $hold, bool $settled = true): void
    {
        // Settled first, because a reviewer message is acknowledged after its call ended, and the next case must not see it.
        if ($settled) {
            $this->waitUntil(fn (): bool => 0 === $this->database()->queued(ReviewWithReviewer::class), 'a reviewer message stayed on the transport');
        }

        new Filesystem()->remove([$this->script.'/calls', ...array_map(fn (string $file): string => $this->script.'/'.$file, [ScriptDirectory::HOLD, ScriptDirectory::RELEASE, ScriptDirectory::WEIGHT])]);

        if ($hold) {
            touch($this->script.'/'.ScriptDirectory::HOLD);
        }
    }

    // The second only once the first reviewer's answer is in the ledger, so the two never write it at the same instant (see ScriptDirectory::release()).
    private function releaseOneByOne(): void
    {
        $script = new ScriptDirectory($this->script);
        $answers = $this->answers();
        $script->release(1);
        $this->waitUntil(fn (): bool => $answers < $this->answers(), 'the first released reviewer did not answer: '.$this->consumer()->output());
        $script->release(2);
        $this->waitForJudge();
    }

    private function answers(): int
    {
        return array_sum(array_map($this->database()->ledgerRows(...), ['verdict_received', 'reviewer_stopped', 'reviewer_failed', 'reviewer_blocked']));
    }

    private function waitForJudge(): void
    {
        $this->waitUntil(fn (): bool => $this->round === $this->database()->ledgerRows('judge_done'), 'the round was not judged: '.$this->consumer()->output());
    }

    /**
     * @param list<string> $options
     */
    private function consume(array $options): ConsumerProcess
    {
        $consumer = ConsumerProcess::start(self::TRANSPORT, $options, [...$this->environment(), self::QUEUE_VARIABLE => self::SHARED_QUEUE, 'JOURNEY_SCRIPT' => $this->script, 'DASHBOARD_PORT' => (string) $this->server?->port, 'REVIEW_MCP_BASE_URL' => (string) $this->server?->address()]);
        $this->consumers[] = $consumer;

        return $consumer;
    }

    private function consumer(): ConsumerProcess
    {
        return array_last($this->consumers) ?? throw new RuntimeException('No consumer was started.');
    }

    /**
     * @param list<string> $options
     */
    private function consumeToTheLimit(array $options): void
    {
        $consumer = $this->consume(['--concurrency='.self::CONCURRENCY, '--limit=1', '--time-limit=60', ...$options]);

        self::assertNotNull($consumer->endedWithin(self::WAIT_SECONDS, MonotonicSeconds::now()), 'the consumer did not stop at its limit: '.$consumer->output());
        self::assertSame(0, $consumer->exitCode(), $consumer->output());
    }

    private function endGracefully(ConsumerProcess $consumer): void
    {
        $signalled = $consumer->terminate();

        self::assertNotNull($consumer->endedWithin(self::GRACE_SECONDS, $signalled), 'the consumer did not end on SIGTERM: '.$consumer->output());
    }

    private function releaseAccountPlaces(): void
    {
        foreach ($this->held as $semaphore) {
            $semaphore->release();
        }

        $this->held = [];
    }

    private function takeAccountPlaces(int $places): void
    {
        $factory = $this->serviceWith(SemaphoreFactory::class, $this->environment());

        for ($place = 0; $place < $places; ++$place) {
            $semaphore = $factory->createSemaphore('vendor-account-'.Provider::Anthropic->value, self::ACCOUNT_CAP, ttlInSecond: 3600.0);
            self::assertTrue($semaphore->acquire(), 'the test could not take a place of the account');
            $this->held[] = $semaphore;
        }
    }

    /**
     * @param callable(): bool $condition
     */
    private function waitUntil(callable $condition, string $failure): void
    {
        $until = MonotonicSeconds::now() + self::WAIT_SECONDS;

        while (MonotonicSeconds::now() < $until) {
            if ($condition()) {
                return;
            }

            usleep(50000);
        }

        self::fail($failure);
    }

    private function database(): InstanceDatabase
    {
        return InstanceDatabase::ofTheInstance();
    }

    /**
     * @return array<string,string>
     */
    private function environment(): array
    {
        return ['ANTHROPIC_API_KEY' => self::PLACEHOLDER_KEY, 'INTAKT_CONFIG' => $this->configuration()];
    }

    private function configuration(): string
    {
        return $this->directory.'/intakt.yaml';
    }
}
