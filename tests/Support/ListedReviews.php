<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\FailureInWords;
use Symfony\Component\Clock\MockClock;

// Built by hand because these tests read the review list without the container; the list shows no cost, ending or replay mark, so every round is unpriced, unended (FixedRoundEndings gives no ending by default) and live.
final class ListedReviews
{
    public static function on(Ledger $ledger): LedgerReviews
    {
        return new LedgerReviews(new LedgerReviewPages(new MockClock(),$ledger, new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())), new UnpricedRoundCosts(), new ChangeDecision(), new FailureInWords(), new FixedRoundEndings(), new ReplayMarks(new ReplayMarkBus())));
    }
}
