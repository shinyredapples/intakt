<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ReplayDemo;

use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\DemoTour;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\ReplayedJudgedState;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Dashboard\Infrastructure\TourCaptionUpdates;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\RoundUpdates;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Intakt\Tests\Support\FixedRoundEndings;
use Intakt\Tests\Support\StoredSerializerFactory;
use Symfony\Bridge\Twig\Extension\RoutingExtension;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Built on the replay's own ledger and marks, because the caption reads the rows the replay appends.
final class TourCaptionsOn
{
    public static function captions(LiveUpdates $updates, DemoTour $tour): TourCaptionUpdates
    {
        return new TourCaptionUpdates($tour, self::twig(), $updates);
    }

    public static function tour(Ledger $ledger, ReplayMarks $marks, string $composeService): DemoTour
    {
        return new DemoTour(self::pages($ledger, $marks), $composeService);
    }

    // Recorded by default, because most replay tests check the recorded updates alone.
    public static function judged(Ledger $ledger, ReplayMarks $marks, RoundUpdates $rounds = new RecordedRoundUpdates()): ReplayedJudgedState
    {
        return new ReplayedJudgedState(self::pages($ledger, $marks), $rounds);
    }

    private static function pages(Ledger $ledger, ReplayMarks $marks): LedgerReviewPages
    {
        $payloads = new LedgerPayloads(StoredSerializerFactory::create());

        return new LedgerReviewPages(new MockClock(),$ledger, new RoundPayloads($payloads), new RoundLedgerReading($ledger, $payloads, new ConfiguredPrices([])), new ChangeDecision(), new FailureInWords(), new FixedRoundEndings(), $marks);
    }

    // The shipped templates with the one route the caption links, so the stream is the text a page receives.
    private static function twig(): Environment
    {
        $routes = new RouteCollection();
        $routes->add(DashboardRoutes::SHOW_REVIEW, new Route('/reviews/{round}'));
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 5).'/templates'));
        $twig->addExtension(new RoutingExtension(new UrlGenerator($routes, new RequestContext())));

        return $twig;
    }
}
