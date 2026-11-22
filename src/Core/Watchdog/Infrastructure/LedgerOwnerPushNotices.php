<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Infrastructure;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Watchdog\Domain\OwnerPushNoticePayload;

// Keyed by project and commit, never by the repository, because every clone of a project reads the same ledger and must give the notice only once.
final readonly class LedgerOwnerPushNotices
{
    public function __construct(
        private Ledger $ledger,
        private LedgerPayloads $payloads,
    ) {
    }

    /**
     * @return list<string>
     */
    public function noticedOf(string $project): array
    {
        $noticed = [];
        foreach ($this->ledger->readOfKind(LedgerEventKind::OwnerPushNoticed) as $recorded) {
            $payload = $this->payloads->of($recorded, OwnerPushNoticePayload::class);
            if ($payload instanceof OwnerPushNoticePayload && $payload->project === $project) {
                $noticed[] = $payload->commit;
            }
        }

        return $noticed;
    }

    /**
     * @throws NoticeNotRecorded
     */
    public function notice(string $project, string $commit): void
    {
        $payload = ['project' => $project, 'commit' => $commit];
        $result = $this->ledger->append(new LedgerEvent(LedgerEventKind::OwnerPushNoticed, json_encode($payload, JSON_THROW_ON_ERROR)));
        if (!$result->succeeded()) {
            throw NoticeNotRecorded::noticing($commit, (string) $result->failure);
        }
    }
}
