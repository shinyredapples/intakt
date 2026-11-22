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
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

// Starts a round the way the dashboard does (the command bus) and lets the worker run one message at a time, so a journey can read the live updates between steps.
final readonly class LiveRoundDriver
{
    private const string TRANSPORT = 'async';
    private const int FIRST_ROUND = 1;
    // A step with an empty queue waits this long, so a stalled round reaches the deadline below in few steps.
    private const int STEP_SECONDS = 5;
    // Well inside the journeys gate's 240 seconds per journey, so a stalled round fails with this driver's message, not the gate's timeout, while the journey's other steps keep their share.
    private const int STEPPING_SECONDS = 150;
    // Matched on this text because the template of the round_changed stream prints it once the judge has ruled and the update carries no other flag.
    private const string ENDED_TEXT = 'Checked.';

    /**
     * @param Closure(list<string>): string $console
     * @param Closure(): list<Update>       $updates
     */
    public function __construct(
        private MessageBusInterface $commands,
        private Closure $console,
        private Closure $updates,
        private ClockInterface $clock,
    ) {
    }

    public function startOnSamplePullRequest(string $repository): string
    {
        $round = new RequestedRound(SampleRepository::BRANCH, self::FIRST_ROUND, new ChangeSource($repository, SampleRepository::BASE));
        $handled = $this->commands->dispatch(new StartReview(SampleProject::NAME, SampleProject::TASK, $round))->last(HandledStamp::class);
        $id = $handled?->getResult();

        return is_string($id) ? $id : throw new RuntimeException('The review start returned no round id.');
    }

    public function step(int $seconds = self::STEP_SECONDS): void
    {
        ($this->console)(['messenger:consume', self::TRANSPORT, '--limit=1', '--time-limit='.$seconds]);
    }

    /**
     * @return list<Update>
     */
    public function stepUntilEnded(string $round): array
    {
        $deadline = $this->clock->now()->getTimestamp() + self::STEPPING_SECONDS;

        while (($left = $deadline - $this->clock->now()->getTimestamp()) > 0) {
            $this->step(min(self::STEP_SECONDS, $left));
            $published = $this->updatesOf($round);

            if ($this->ended($published)) {
                return $published;
            }
        }

        $last = array_last($this->updatesOf($round));

        throw new RuntimeException(sprintf('Round %s did not end within %d seconds of steps; the last update was %s.', $round, self::STEPPING_SECONDS, $last?->getData() ?? 'none'));
    }

    /**
     * @return list<Update>
     */
    private function updatesOf(string $round): array
    {
        $topic = MercureRoundUpdates::topicOf($round);

        return array_values(array_filter(($this->updates)(), static fn (Update $update): bool => in_array($topic, $update->getTopics(), true)));
    }

    /**
     * @param list<Update> $published
     */
    private function ended(array $published): bool
    {
        return array_any($published, static fn (Update $update): bool => str_contains($update->getData(), self::ENDED_TEXT));
    }
}
