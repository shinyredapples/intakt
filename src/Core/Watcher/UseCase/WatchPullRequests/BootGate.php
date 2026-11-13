<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Review\Contract\BootAt;
use Intakt\Core\Review\Contract\BootState;
use Intakt\Core\Review\Contract\MainCommit;
use Intakt\Core\Watcher\Domain\BootKey;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Plugin\Forge\Contract\CommitId;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

// A head goes back only when it fails while main boots, because a head that boots, a boot step that crashed and a main that fails itself all say nothing against the head; nothing here throws, because the gate must never stop the review.
final readonly class BootGate
{
    // Nothing computes a fingerprint of the boot tools yet, so one constant stands for them; changing the tools means changing it.
    public const string TOOL_FINGERPRINT = 'boot-tools-1';

    public function __construct(
        #[Autowire(service: 'query.bus')]
        private MessageBusInterface $queries,
        private BootResults $results,
        private LoggerInterface $logger,
    ) {
    }

    public function admit(WatchedProject $project, HeadDecision $green): HeadDecision
    {
        try {
            return $this->judged($project, $green);
        } catch (Throwable $failure) {
            $this->logger->error('The boot gate failed, so the review starts without it.', ['exception' => $failure]);

            return $green;
        }
    }

    private function judged(WatchedProject $project, HeadDecision $green): HeadDecision
    {
        $main = $this->mainCommit($project);
        $mainOutcome = $this->results->ofMain(new BootKey($project->name, $main, self::TOOL_FINGERPRINT), $project->source);

        if (BootState::Booted !== $mainOutcome->state) {
            return $green;
        }

        $head = $this->results->ofHead(new BootAt($project->source, $green->change->head), $main);

        return BootState::Failed === $head->state ? $green->failedBoot($head->reason) : $green;
    }

    private function mainCommit(WatchedProject $project): CommitId
    {
        $main = $this->queries->dispatch(new MainCommit($project->source))->last(HandledStamp::class)?->getResult();

        return $main instanceof CommitId ? $main : throw new LogicException('The main commit query did not answer a commit.');
    }
}
