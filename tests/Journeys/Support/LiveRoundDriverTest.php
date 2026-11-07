<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Closure;
use Intakt\Core\Dashboard\Infrastructure\MercureRoundUpdates;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\StartReview;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleProject;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleRepository;
use Intakt\Gates\JourneysGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use RuntimeException;
use stdClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[CoversClass(LiveRoundDriver::class)]
#[UsesClass(StartReview::class)]
#[UsesClass(RequestedRound::class)]
#[UsesClass(ChangeSource::class)]
#[UsesClass(MercureRoundUpdates::class)]
final class LiveRoundDriverTest extends TestCase
{
    private const string ROUND = '0198a1b2-0000-7000-8000-000000000001';
    private const string OTHER_ROUND = '0198a1b2-0000-7000-8000-000000000002';
    // What a console process of the worker takes to boot and stop around its wait, generously.
    private const int CONSOLE_OVERHEAD_SECONDS = 3;

    public function testStartingARoundReviewsTheSamplePullRequestAndReturnsTheRoundId(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::callback(
            static fn (StartReview $command): bool => SampleProject::NAME === $command->project
                && SampleRepository::BRANCH === $command->round->change
                && SampleRepository::BASE === $command->source->base
                && '/sample' === $command->source->repository
                && 1 === $command->round->number,
        ))->willReturn(new Envelope(new stdClass(), [new HandledStamp(self::ROUND, 'handler')]));

        $driver = new LiveRoundDriver($bus, static fn (array $arguments): string => '', static fn (): array => [], new MockClock());

        self::assertSame(self::ROUND, $driver->startOnSamplePullRequest('/sample'));
    }

    public function testAStepConsumesExactlyOneQueuedMessage(): void
    {
        $calls = [];
        $driver = new LiveRoundDriver(self::createStub(MessageBusInterface::class), static function (array $arguments) use (&$calls): string {
            $calls[] = $arguments;

            return '';
        }, static fn (): array => [], new MockClock());

        $driver->step();

        self::assertCount(1, $calls);
        self::assertContains('--limit=1', $calls[0]);
        self::assertSame('messenger:consume', $calls[0][0]);
    }

    public function testTheDriverStepsUntilTheUpdatesShowTheRoundEndedAndReturnsThem(): void
    {
        $clock = new MockClock();
        $steps = 0;
        $reads = 0;
        $driver = new LiveRoundDriver(self::createStub(MessageBusInterface::class), static function (array $arguments) use (&$steps, $clock): string {
            ++$steps;
            $clock->sleep(1);

            return '';
        }, function () use (&$reads): array {
            $progress = $this->updateFor(self::ROUND, '1 of 2 reviewers finished.');

            return 3 === ++$reads ? [$progress, $this->updateFor(self::ROUND, 'Judged.')] : [$progress];
        }, $clock);

        $published = $driver->stepUntilEnded(self::ROUND);

        self::assertSame(3, $steps);
        self::assertCount(2, $published);
        self::assertStringContainsString('Judged.', $published[1]->getData());
    }

    public function testARoundThatNeverEndsFailsNamingTheRoundAndTheLastUpdate(): void
    {
        $clock = new MockClock();
        $driver = new LiveRoundDriver(self::createStub(MessageBusInterface::class), $this->waitingWorker($clock), fn (): array => [$this->updateFor(self::ROUND, '0 of 2 reviewers finished.')], $clock);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Round '.self::ROUND.' did not end within \d+ seconds of steps.*0 of 2 reviewers finished\./');

        $driver->stepUntilEnded(self::ROUND);
    }

    public function testARoundThatNeverEndsFailsWithTheDriversMessageBeforeTheJourneyTimeLimit(): void
    {
        $clock = new MockClock();
        $started = $clock->now();
        $driver = new LiveRoundDriver(self::createStub(MessageBusInterface::class), $this->waitingWorker($clock), static fn (): array => [], $clock);

        try {
            $driver->stepUntilEnded(self::ROUND);
            self::fail('a round that never ends ended');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('did not end', $failure->getMessage());
        }

        $journeySeconds = (int) new ReflectionClassConstant(JourneysGate::class, 'JOURNEY_SECONDS')->getValue();
        self::assertLessThan($journeySeconds, $clock->now()->getTimestamp() - $started->getTimestamp());
    }

    public function testUpdatesPublishedForAnotherRoundDoNotEndTheDrivenRound(): void
    {
        $clock = new MockClock();
        $driver = new LiveRoundDriver(self::createStub(MessageBusInterface::class), $this->waitingWorker($clock), fn (): array => [$this->updateFor(self::OTHER_ROUND, 'Judged.')], $clock);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/the last update was none/');

        $driver->stepUntilEnded(self::ROUND);
    }

    // A worker on an empty queue: it waits its whole time limit and handles nothing.
    private function waitingWorker(MockClock $clock): Closure
    {
        return static function (array $arguments) use ($clock): string {
            $limit = array_find($arguments, static fn (string $argument): bool => str_starts_with($argument, '--time-limit='));
            $clock->sleep((int) substr($limit ?? '', strlen('--time-limit=')) + self::CONSOLE_OVERHEAD_SECONDS);

            return '';
        };
    }

    private function updateFor(string $round, string $text): Update
    {
        return new Update(MercureRoundUpdates::topicOf($round), '<p>'.$text.'</p>', true);
    }
}
