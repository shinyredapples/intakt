<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Budget\Contract\OwnerNotices;
use Intakt\Core\Budget\Domain\OutageNoticeRecord;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(OwnerNotices::class)]
final readonly class DoctrineOwnerNotices implements OwnerNotices
{
    public function __construct(
        private EntityManagerInterface $entities,
    ) {
    }

    // The position breaks a tie of one moment, so two notices noticed together keep the order they were stored in.
    #[Override]
    public function oldestFirst(): array
    {
        $records = $this->entities->getRepository(OutageNoticeRecord::class)->findBy([], ['noticedAt' => 'ASC', 'position' => 'ASC']);

        return array_map(
            static fn (OutageNoticeRecord $record): OutageNotice => new OutageNotice(new Account($record->provider, $record->account), $record->lastReadingAt, $record->noticedAt),
            $records,
        );
    }
}
