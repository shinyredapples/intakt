<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\Infrastructure;

use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Watchdog\Domain\OwnerPushNoticePayload;
use Intakt\Core\Watchdog\Infrastructure\LedgerOwnerPushNotices;
use Intakt\Core\Watchdog\Infrastructure\NoticeNotRecorded;
use Intakt\Tests\Core\Watchdog\HaltLedger;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LedgerOwnerPushNotices::class)]
#[CoversClass(OwnerPushNoticePayload::class)]
#[CoversClass(NoticeNotRecorded::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(AppendResult::class)]
final class LedgerOwnerPushNoticesTest extends TestCase
{
    private const string COMMIT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private HaltLedger $ledger;
    private LedgerOwnerPushNotices $notices;

    public function testANoticedCommitCountsAsNoticedForItsProject(): void
    {
        $this->notices->notice('acme/shop', self::COMMIT);

        self::assertSame([self::COMMIT], $this->notices->noticedOf('acme/shop'));
        self::assertSame([LedgerEventKind::OwnerPushNoticed], $this->ledger->kinds());
        self::assertSame(['{"project":"acme\/shop","commit":"'.self::COMMIT.'"}'], $this->ledger->payloads());
    }

    public function testACommitNoNoticeNamesIsNotNoticed(): void
    {
        $this->notices->notice('acme/shop', str_repeat('b', 40));

        self::assertNotContains(self::COMMIT, $this->notices->noticedOf('acme/shop'));
    }

    public function testACommitNoticedForAnotherProjectIsNotNoticedForThisOne(): void
    {
        $this->notices->notice('acme/blog', self::COMMIT);

        self::assertSame([], $this->notices->noticedOf('acme/shop'));
    }

    public function testANoticeRowThatDoesNotReadNoticesNothing(): void
    {
        $this->ledger->append(new LedgerEvent(LedgerEventKind::OwnerPushNoticed, '{"project":"acme/shop"}'));

        self::assertSame([], $this->notices->noticedOf('acme/shop'));
    }

    public function testANoticeTheLedgerRefusesThrowsAndLeavesTheCommitUnnoticed(): void
    {
        $this->ledger->refuseAppends();

        try {
            $this->notices->notice('acme/shop', self::COMMIT);
            self::fail('A refused notice must throw.');
        } catch (NoticeNotRecorded $refused) {
            self::assertSame('The notice for '.self::COMMIT.' was not recorded, because the ledger refused it: the ledger is down.', $refused->getMessage());
        }

        self::assertSame([], $this->notices->noticedOf('acme/shop'));
    }

    protected function setUp(): void
    {
        $this->ledger = new HaltLedger();
        $this->notices = new LedgerOwnerPushNotices($this->ledger, new LedgerPayloads(StoredSerializerFactory::create()));
    }
}
