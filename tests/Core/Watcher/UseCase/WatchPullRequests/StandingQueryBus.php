<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Review\Contract\HeadIdentity;
use Intakt\Core\Review\Contract\IdentityAtHead;
use Intakt\Core\Review\Contract\RoundVerdicts;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Contract\VerdictsOfRound;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\HeadMove;
use Intakt\Plugin\Forge\Contract\HeadMoveKind;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

// Judges with the real identity rules and clears the entity manager, because the real handlers of both queries do, for a round recorded on one identity.
final class StandingQueryBus implements MessageBusInterface
{
    /** @var list<VerdictsOfRound> */
    public array $asked = [];
    public HeadMove $move;
    public bool $fetched = true;

    public function __construct(
        private readonly VerdictIdentity $recorded,
        private readonly EntityManagerInterface $entities,
    ) {
        $this->move = new HeadMove(HeadMoveKind::Unmoved, []);
    }

    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $answer = $message instanceof IdentityAtHead ? $this->identityAt($message) : $this->verdictsOf($message);

        return new Envelope($message, [new HandledStamp($answer, 'handler')]);
    }

    // Fails like the local repository does for a commit it lacks.
    private function identityAt(IdentityAtHead $query): HeadIdentity
    {
        if (!$this->fetched) {
            throw new ForgeReadFailed('The commit is not in the repository.');
        }

        $commits = new CommitRange($this->recorded->commits->base, $query->round->head);

        return new HeadIdentity(new VerdictIdentity($commits, $this->recorded->terms), $this->move);
    }

    private function verdictsOf(object $query): RoundVerdicts
    {
        assert($query instanceof VerdictsOfRound);
        $this->asked[] = $query;
        $this->entities->clear();

        return new RoundVerdicts($this->recorded->standingAt($query->current, $query->move), []);
    }
}
