<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\History;
use Intakt\Core\Dashboard\Domain\HistoryRow;
use Intakt\Core\Dashboard\UseCase\ShowHistory\ShowHistoryController;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\PayloadFields;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ShowHistoryController::class)]
#[UsesClass(History::class)]
#[UsesClass(HistoryRow::class)]
#[UsesClass(PayloadFields::class)]
final class ShowHistoryControllerTest extends DashboardPageTestCase
{
    public function testTheHistoryPageListsLedgerEventsNewestFirst(): void
    {
        $this->record(['actor' => 'owner', 'project' => 'alpha', 'before' => 'low', 'after' => 'high']);
        $this->record(['actor' => 'maintainer', 'project' => 'beta', 'expires_at' => '2026-12-01T10:00:00+00:00']);

        $crawler = $this->browser->request('GET', '/history');

        self::assertResponseIsSuccessful();
        self::assertSame(['maintainer', 'owner'], $crawler->filter('.ledger .who')->each(static fn ($who): string => $who->text()));
        self::assertSame('2026-12-01 10:00:00 UTC', $crawler->filter('.ledger tbody tr')->first()->filter('td')->last()->text());
        self::assertSame('low → high', $crawler->filter('.ledger tbody tr')->last()->filter('.change')->text());
    }

    public function testTheHistoryPageLinksToTrendsAsASubTabAndMarksLedgerAsCurrent(): void
    {
        $this->record(['actor' => 'owner']);

        $crawler = $this->browser->request('GET', '/history');

        self::assertSame('Ledger', $crawler->filter('.subtabs a[aria-current="page"]')->text());
        self::assertSame('/trends', $crawler->filter('.subtabs a:not([aria-current])')->attr('href'));
    }

    public function testAHistoryRowWithoutAChangeShowsNoArrow(): void
    {
        $this->record(['actor' => 'owner', 'before' => 'same', 'after' => 'same']);

        $crawler = $this->browser->request('GET', '/history');

        self::assertStringNotContainsString('→', $crawler->filter('.ledger tbody tr')->text());
    }

    public function testAProjectLinkKeepsEveryProjectsRowsSoTheFilterCanShowThemAgain(): void
    {
        $this->record(['actor' => 'owner', 'project' => 'alpha']);
        $this->record(['actor' => 'maintainer', 'project' => 'beta']);

        $crawler = $this->browser->request('GET', '/history?project=beta');

        self::assertSame(['beta', 'alpha'], $crawler->filter('.ledger tbody tr')->each(static fn ($row): string => (string) $row->attr('data-project')));
    }

    public function testAProjectWithOnlyWatcherEventsCanBeChosenInTheHistoryFilter(): void
    {
        // The watcher's payload for a main that fails to boot, because no review or build follows it and the shared filter never lists such a project.
        $payload = ['project' => 'beta', 'main' => str_repeat('a', 40), 'tool_fingerprint' => 'sample', 'reason' => 'sample'];
        $this->ledger->append(new LedgerEvent(LedgerEventKind::MainBootFailed, json_encode($payload, JSON_THROW_ON_ERROR)));

        $crawler = $this->browser->request('GET', '/history?project=beta');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.ledger tr[data-project="beta"]'));
        self::assertCount(1, $crawler->filter('#projfilter option[value="beta"][selected]'));
    }

    public function testTheHistoryFilterOffersItsRowsProjectsInNameOrderWithTheOthers(): void
    {
        $round = Uuid::v7()->toRfc4122();
        $this->ledger->append(new LedgerEvent(LedgerEventKind::ReviewStarted, json_encode(['round' => $round, 'project' => 'Beta', 'reviewers' => 1, 'change' => 'sample', 'number' => 1, 'task' => 'sample'], JSON_THROW_ON_ERROR)));
        $this->record(['actor' => 'owner', 'project' => 'gamma']);
        $this->record(['actor' => 'owner', 'project' => 'alpha']);
        $this->record(['actor' => 'owner', 'project' => 'alpha']);
        $this->record(['actor' => 'owner', 'project' => '']);

        $crawler = $this->browser->request('GET', '/history');

        self::assertSame(['', 'alpha', 'Beta', 'gamma'], $crawler->filter('#projfilter option')->each(static fn ($option): string => (string) $option->attr('value')));
    }

    public function testTheHistoryPageMarksOnlyTheProjectsOutsideTheSharedFilterAsItsOwn(): void
    {
        $round = Uuid::v7()->toRfc4122();
        $this->ledger->append(new LedgerEvent(LedgerEventKind::ReviewStarted, json_encode(['round' => $round, 'project' => 'Beta', 'reviewers' => 1, 'change' => 'sample', 'number' => 1, 'task' => 'sample'], JSON_THROW_ON_ERROR)));
        $this->record(['actor' => 'owner', 'project' => 'Beta']);
        $this->record(['actor' => 'owner', 'project' => 'alpha']);

        $crawler = $this->browser->request('GET', '/history');

        self::assertSame(['alpha'], $crawler->filter('#projfilter option[data-page-option]')->each(static fn ($option): string => (string) $option->attr('value')));
    }

    public function testTheHistoryPageShowsASecretOnlyAsItsFingerprint(): void
    {
        $this->record(['actor' => 'owner', 'fingerprint' => 'ab12cd', 'before' => 'sk-old-secret', 'after' => 'sk-new-secret']);

        $this->browser->request('GET', '/history');

        self::assertSelectorTextContains('.fingerprint code', 'ab12cd');
        self::assertStringNotContainsString('-secret', (string) $this->browser->getResponse()->getContent());
    }

    public function testTheHistoryPageShowsAnEmptyStateWhenTheLedgerHasNoEvents(): void
    {
        $crawler = $this->browser->request('GET', '/history');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.ledger tbody tr'));
        self::assertStringContainsString('No history yet', $crawler->text());
    }

    /**
     * @param array<string, string> $payload
     */
    private function record(array $payload): void
    {
        $this->ledger->append(new LedgerEvent(LedgerEventKind::OwnerAsked, json_encode($payload, JSON_THROW_ON_ERROR)));
    }
}
