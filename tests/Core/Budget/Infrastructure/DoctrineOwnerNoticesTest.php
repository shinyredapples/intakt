<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Budget\Domain\OutageNoticeRecord;
use Intakt\Core\Budget\Infrastructure\DoctrineOwnerNotices;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;

#[Group('persistence')]
#[CoversClass(DoctrineOwnerNotices::class)]
#[UsesClass(OutageNotice::class)]
#[UsesClass(OutageNoticeRecord::class)]
#[UsesClass(Account::class)]
final class DoctrineOwnerNoticesTest extends KernelTestCase
{
    private EntityManagerInterface $entities;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
    }

    public function testOutageNoticesAreListedOldestFirst(): void
    {
        $this->store('later', new DateTimeImmutable('2026-11-02T12:00:00+00:00'));
        $this->store('first', new DateTimeImmutable('2026-11-02T09:00:00+00:00'));

        $accounts = array_map(static fn (OutageNotice $notice): string => $notice->account->name, $this->notices()->oldestFirst());

        self::assertSame(['first', 'later'], $accounts);
    }

    public function testNoticesNoticedTogetherKeepTheOrderTheyWereStoredIn(): void
    {
        $this->store('b', new DateTimeImmutable('2026-11-02T09:00:00+00:00'));
        $this->store('a', new DateTimeImmutable('2026-11-02T09:00:00+00:00'));

        $accounts = array_map(static fn (OutageNotice $notice): string => $notice->account->name, $this->notices()->oldestFirst());

        self::assertSame(['b', 'a'], $accounts);
    }

    public function testANoticeCarriesItsAccountAndTheLastReading(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T09:00:00+00:00'));

        $notice = $this->notices()->oldestFirst()[0];

        self::assertSame('claude_code.main', $notice->account->key());
        self::assertSame('2026-11-02 08:00:00', $notice->lastReadingAt->format('Y-m-d H:i:s'));
    }

    public function testNoNoticeGivesAnEmptyList(): void
    {
        self::assertSame([], $this->notices()->oldestFirst());
    }

    private function notices(): DoctrineOwnerNotices
    {
        return new DoctrineOwnerNotices($this->entities);
    }

    private function store(string $account, DateTimeImmutable $noticedAt): void
    {
        $this->entities->persist(new OutageNoticeRecord(new Account(Provider::ClaudeCode, $account), new DateTimeImmutable('2026-11-02T08:00:00+00:00'), $noticedAt));
        $this->entities->flush();
    }
}
