<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Review\Contract\RoundVerdicts;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Contract\VerdictsOfRound;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

// Judges the standing with the real identity rules, as the handler of the query does, for a round recorded on one identity.
final class StandingQueryBus implements MessageBusInterface
{
    /** @var list<VerdictsOfRound> */
    public array $asked = [];

    public function __construct(private readonly VerdictIdentity $recorded)
    {
    }

    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        assert($message instanceof VerdictsOfRound);
        $this->asked[] = $message;
        $verdicts = new RoundVerdicts($this->recorded->standingAt($message->current, $message->move), []);

        return new Envelope($message, [new HandledStamp($verdicts, 'handler')]);
    }
}
