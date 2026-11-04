<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Dashboard\Domain\FailingHead;
use Intakt\Core\Dashboard\Domain\HeadFault;
use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\LedgerPayload;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Platform\Infrastructure\HeldCredentialRedactor;
use Intakt\Core\Platform\Infrastructure\HeldCredentials;
use Intakt\Core\Watcher\Domain\HeadFailure;
use Intakt\Core\Watcher\Domain\PendingHead;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\FailedHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\PendingHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatcherLedger;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordedForge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[Group('persistence')]
#[CoversClass(FailedHeads::class)]
#[UsesClass(PendingHeads::class)]
#[UsesClass(PendingHead::class)]
#[UsesClass(HeadFailure::class)]
#[UsesClass(WatcherLedger::class)]
#[UsesClass(HeldCredentialRedactor::class)]
#[UsesClass(HeldCredentials::class)]
#[UsesClass(Redaction::class)]
#[UsesClass(LedgerFailingHeads::class)]
#[UsesClass(LedgerPayload::class)]
#[UsesClass(FailingHead::class)]
#[UsesClass(HeadFault::class)]
final class FailedHeadsTest extends KernelTestCase
{
    // Named in config/services.yaml among the held credentials, and its value has no shape the redaction knows.
    private const string HELD_NAME = 'OPENAI_COMPATIBLE_API_KEY';
    private const string HELD_VALUE = 'plainheldkeyvalue0123456789';

    private EntityManagerInterface $entities;
    private MockClock $clock;
    private PendingHeads $pending;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->clock = new MockClock('2026-11-04T10:00:00+00:00');
        $this->pending = new PendingHeads($entities, $this->clock);
    }

    protected function tearDown(): void
    {
        unset($_SERVER[self::HELD_NAME]);
        parent::tearDown();
    }

    public function testTheSameHeadListedAgainWhileItsFourthFailureIsRecordedStaysDueWithoutFailures(): void
    {
        $old = RecordedForge::change('7', 'a', '2026-11-04T09:00:00Z');
        $listedAgain = RecordedForge::change('7', 'a', '2026-11-04T09:30:00Z');
        $this->pending->enqueue('alpha', [$old]);
        $this->pending->backOff(new HeadFailure('alpha', $old, 'RuntimeException: down'), 3, $this->clock->now());
        $pending = $this->pending;
        $ledger = self::createStub(Ledger::class);
        $ledger->method('append')->willReturnCallback(static function () use ($pending, $listedAgain): AppendResult {
            $pending->enqueue('alpha', [$listedAgain]);

            return AppendResult::appended(Uuid::v7());
        });

        $this->failedHeads($ledger)->backOff('alpha', $old, new RuntimeException('down'));

        self::assertCount(1, $this->pending->dueFor('alpha'));
        self::assertSame(0, $this->pending->failuresOf('alpha', $listedAgain));
    }

    public function testAHeldCredentialInAFailureMessageReachesNeitherTheLedgerNorTheCard(): void
    {
        $_SERVER[self::HELD_NAME] = self::HELD_VALUE;
        $head = RecordedForge::change('7', 'a', '2026-11-04T09:00:00Z');
        $this->pending->enqueue('alpha', [$head]);
        $ledger = new DashboardLedger();

        $this->failedHeads($ledger)->backOff('alpha', $head, new RuntimeException('401 Unauthorized for key '.self::HELD_VALUE));

        $cards = new LedgerFailingHeads($ledger)->now();
        self::assertCount(1, $cards);
        self::assertSame('RuntimeException: 401 Unauthorized for key [redacted]', $cards[0]->fault->cause);
        foreach ($ledger->readInOrder() as $recorded) {
            self::assertStringNotContainsString(self::HELD_VALUE, $recorded->event->payload);
        }
    }

    // The container's redactor, so the test proves the wiring: the names config/services.yaml holds, behind the Platform contract.
    private function failedHeads(Ledger $ledger): FailedHeads
    {
        $redactor = self::getContainer()->get(Redactor::class);
        self::assertInstanceOf(Redactor::class, $redactor);

        return new FailedHeads($this->pending, new WatcherLedger($ledger), $this->clock, $redactor);
    }
}
