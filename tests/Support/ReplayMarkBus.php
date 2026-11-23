<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Intakt\Core\Review\Contract\FlagProjectAsReplay;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\ReplayMarkOfProject;
use Intakt\Core\Review\Contract\ReplayMarkOfRound;
use Intakt\Core\Review\UseCase\ReadVerdicts\RoundNotFound;
use LogicException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

// Answers the replay mark queries and takes the flag command as the Review module would, so a dashboard test needs no database for the mark; a round not in $stored is refused as unknown, as a round only the ledger names is; flagged after the kernel booted, because the container refuses to replace a service it already built.
final class ReplayMarkBus implements MessageBusInterface
{
    /**
     * @param list<string>              $replays
     * @param array<string, ReplayMark> $stored
     */
    public function __construct(private array $replays = [], private array $stored = [])
    {
    }

    public function flag(string $project): void
    {
        $this->replays[] = $project;
    }

    public function store(string $round, ReplayMark $mark): void
    {
        $this->stored[$round] = $mark;
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $envelope = new Envelope($message, $stamps);

        return match (true) {
            $message instanceof FlagProjectAsReplay => $this->flagged($envelope, $message->project),
            $message instanceof ReplayMarkOfProject => $envelope->with(new HandledStamp(in_array($message->project, $this->replays, true) ? ReplayMark::Replay : ReplayMark::Live, 'replay marks')),
            $message instanceof ReplayMarkOfRound => $envelope->with(new HandledStamp($this->stored[$message->roundId] ?? throw new HandlerFailedException($envelope, [RoundNotFound::for($message->roundId)]), 'replay marks')),
            default => throw new LogicException(sprintf('Only the replay mark of a project or a round is answered, and a project flagged, not %s.', $message::class)),
        };
    }

    /**
     * @return list<string>
     */
    public function replays(): array
    {
        return $this->replays;
    }

    private function flagged(Envelope $envelope, string $project): Envelope
    {
        if (!in_array($project, $this->replays, true)) {
            $this->flag($project);
        }

        return $envelope->with(new HandledStamp(null, 'replay marks'));
    }
}
