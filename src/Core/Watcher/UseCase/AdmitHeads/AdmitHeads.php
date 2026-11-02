<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\AdmitHeads;

use DateTimeImmutable;
use Intakt\Core\Watcher\Domain\AdmissionDecision;
use Intakt\Core\Watcher\Domain\HeadAdmission;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\HeadChecks;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class AdmitHeads
{
    private const int READ_AGAIN_AFTER_SECONDS = 60;

    public function __construct(
        private HeadChecks $forge,
        private AdmissionDecision $decision,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<OpenChange> $changed
     *
     * @return list<HeadDecision> heads with finished CI first
     *
     * @throws ForgeReadFailed
     */
    public function __invoke(string $project, array $changed): array
    {
        $decisions = array_map(fn (OpenChange $change): HeadDecision => $this->decide($project, $change), $changed);

        return $this->decision->order($decisions);
    }

    private function decide(string $project, OpenChange $change): HeadDecision
    {
        $ci = $this->forge->ciStateOf($project, $change->head);

        if (!$ci->head->equals($change->head)) {
            throw new ForgeReadFailed('The forge answered the CI state of another head.');
        }

        $admission = $this->decision->admissionOf($ci);
        $this->logger->info('Decided a head from its CI.', ['project' => $project, 'change' => $change->id->value, 'head' => $change->head->hash, 'admission' => $admission->value]);

        return new HeadDecision($change, $admission, HeadAdmission::Pending === $admission ? $this->readAgainAt() : null);
    }

    private function readAgainAt(): DateTimeImmutable
    {
        return $this->clock->now()->modify('+'.self::READ_AGAIN_AFTER_SECONDS.' seconds');
    }
}
