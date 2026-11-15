<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\ReplayMarkOfProject;
use LogicException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

// Answers the replay mark query as the Review module would, so a dashboard test needs no database for the mark; flagged after the kernel booted, because the container refuses to replace a service it already built.
final class ReplayMarkBus implements MessageBusInterface
{
    /**
     * @param list<string> $replays
     */
    public function __construct(private array $replays = [])
    {
    }

    public function flag(string $project): void
    {
        $this->replays[] = $project;
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if (!$message instanceof ReplayMarkOfProject) {
            throw new LogicException(sprintf('Only the replay mark of a project is answered, not %s.', $message::class));
        }

        return new Envelope($message, $stamps)->with(new HandledStamp(in_array($message->project, $this->replays, true) ? ReplayMark::Replay : ReplayMark::Live, 'replay marks'));
    }
}
