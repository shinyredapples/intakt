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
        self::assertSame(['maintainer', 'owner'], $crawler->filter('.review-row .who')->each(static fn ($who): string => $who->text()));
        self::assertStringContainsString('Expires 2026-12-01 10:00:00 UTC', $crawler->filter('.review-row')->first()->text());
        self::assertSame('low → high', $crawler->filter('.review-row')->last()->filter('.change')->text());
    }

    public function testTheHistoryPageShowsOnlyTheChosenProjectsEventsWhenFilteredByProject(): void
    {
        $this->record(['actor' => 'owner', 'project' => 'alpha']);
        $this->record(['actor' => 'maintainer', 'project' => 'beta']);

        $crawler = $this->browser->request('GET', '/history?project=beta');

        self::assertSame(['maintainer'], $crawler->filter('.review-row .who')->each(static fn ($who): string => $who->text()));
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
        self::assertCount(0, $crawler->filter('.review-row'));
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
