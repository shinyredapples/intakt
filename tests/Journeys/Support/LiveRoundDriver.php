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
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Mercure\Update;

// Starts a round the way the dashboard does (the command bus) and lets the worker run one message at a time, so a journey can read the live updates between steps.
final readonly class LiveRoundDriver
{
    private const string TRANSPORT = 'async';
    private const int FIRST_ROUND = 1;
    private const int STEP_SECONDS = 20;
    // A round of the sample project queues a reviewer message per panel member and one judgement, so this leaves room to spare.
    private const int STEP_CEILING = 30;
    // What the round_changed stream template prints once the judge has ruled.
    private const string ENDED_TEXT = 'Judged.';

    /**
     * @param Closure(list<string>): string $console
     * @param Closure(): list<Update>       $updates
     */
    public function __construct(
        private MessageBusInterface $commands,
        private Closure $console,
        private Closure $updates,
    ) {
    }

    public function startOnSamplePullRequest(string $repository): string
    {
        $round = new RequestedRound(SampleRepository::BRANCH, self::FIRST_ROUND, new ChangeSource($repository, SampleRepository::BASE));
        $handled = $this->commands->dispatch(new StartReview(SampleProject::NAME, SampleProject::TASK, $round))->last(HandledStamp::class);
        $id = $handled?->getResult();

        return is_string($id) ? $id : throw new RuntimeException('The review start returned no round id.');
    }

    public function step(): void
    {
        ($this->console)(['messenger:consume', self::TRANSPORT, '--limit=1', '--time-limit='.self::STEP_SECONDS]);
    }

    /**
     * @return list<Update> the updates published for the round, the last one reporting its end
     */
    public function stepUntilEnded(string $round): array
    {
        for ($steps = 0; $steps < self::STEP_CEILING; ++$steps) {
            $this->step();
            $published = $this->updatesOf($round);

            if ($this->ended($published)) {
                return $published;
            }
        }

        $last = array_last($this->updatesOf($round));

        throw new RuntimeException(sprintf('Round %s did not end within %d steps; the last update was %s.', $round, self::STEP_CEILING, $last?->getData() ?? 'none'));
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
