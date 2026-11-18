<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Review\Contract\BootAt;
use Intakt\Core\Review\Contract\BootState;
use Intakt\Core\Review\Contract\MainCommit;
use Intakt\Core\Watcher\Domain\BootKey;
use Intakt\Core\Watcher\Domain\FailedBoot;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Plugin\Forge\Contract\AuthorTrust;
use Intakt\Plugin\Forge\Contract\CommitId;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

// A head goes back only when it fails while main boots, because a head that boots, a boot step that crashed and a main that does not boot all say nothing against the head; nothing here throws, because the gate must never stop the review.
#[AsAlias(HeadGate::class)]
final readonly class BootGate implements HeadGate
{
    // Nothing computes a fingerprint of the boot tools yet, so one constant stands for them; changing the tools means changing it.
    public const string TOOL_FINGERPRINT = 'boot-tools-1';
    // Written as the cause of the head_boot_skipped line, so a reader tells a head nobody booted from one that booted.
    public const string UNTRUSTED_AUTHOR = 'boot_skipped_untrusted_author';

    public function __construct(
        #[Autowire(service: 'query.bus')]
        private MessageBusInterface $queries,
        private AuthorTrust $authors,
        private BootResults $results,
        private WatcherLedger $ledger,
        private LoggerInterface $logger,
    ) {
    }

    public function admit(WatchedProject $project, HeadDecision $green): HeadDecision
    {
        try {
            return $this->judged($project, $green);
            // Boundary: logs any failure of the gate and starts the review without it, because the boot only gates a review and must never stop it.
        } catch (Throwable $failure) {
            $this->logger->error('The boot gate failed, so the review starts without it.', ['exception' => $failure]);

            return $green;
        }
    }

    private function judged(WatchedProject $project, HeadDecision $green): HeadDecision
    {
        // Booting runs the head's code on the host, so only a trusted author's head boots; any other is reviewed unbooted: docs/design/05-architecture/14-threat-model.md
        if (!$this->authorTrusted($green)) {
            $this->ledger->headBootSkipped($project->name, $green);

            return $green;
        }

        $main = $this->mainCommit($project);

        if (!$this->mainBoots($project, $main)) {
            return $green;
        }

        $head = $this->results->ofHead(new BootAt($project->source, $green->change->head), $main);

        return BootState::Failed === $head->state ? $green->sentBackFor(new FailedBoot($head->reason)) : $green;
    }

    // A check that throws counts as untrusted, because a check that cannot answer must never let a head run.
    private function authorTrusted(HeadDecision $green): bool
    {
        try {
            return $this->authors->isAuthorTrusted($green->change->id);
            // Boundary: logs any failure of the trust check and counts the author as untrusted, because a check that cannot answer must never let a head run.
        } catch (Throwable $unanswered) {
            $this->logger->warning('The pull request author counts as untrusted, because the trust check failed.', ['change' => $green->change->id->value, 'exception' => $unanswered]);

            return false;
        }
    }

    private function mainCommit(WatchedProject $project): CommitId
    {
        $main = $this->queries->dispatch(new MainCommit($project->source))->last(HandledStamp::class)?->getResult();

        return $main instanceof CommitId ? $main : throw new LogicException('The main commit query did not answer a commit.');
    }

    private function mainBoots(WatchedProject $project, CommitId $main): bool
    {
        return BootState::Booted === $this->results->ofMain(new BootKey($project->name, $main, self::TOOL_FINGERPRINT), $project->source)->state;
    }
}
