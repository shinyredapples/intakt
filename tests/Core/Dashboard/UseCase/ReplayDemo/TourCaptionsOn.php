<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ReplayDemo;

use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\DemoTour;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LiveTrends;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Dashboard\Infrastructure\TourCaptionUpdates;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Tests\Support\FixedRoundEndings;
use Intakt\Tests\Support\StoredSerializerFactory;
use LogicException;
use Override;
use PHPUnit\Framework\Assert;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Symfony\Bridge\Twig\Extension\RoutingExtension;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Throwable;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Built on the replay's own ledger and marks, because the caption and the Trends figures read the rows the replay appends.
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

    // On the kernel's Twig, because the Trends templates use the components' own functions.
    public static function trends(LiveUpdates $updates, Ledger $ledger, ReplayMarks $marks, ContainerInterface $kernel): LiveTrends
    {
        $twig = $kernel->get(Environment::class);
        Assert::assertInstanceOf(Environment::class, $twig);

        return new LiveTrends(self::pages($ledger, $marks), $twig, $updates, new LockFactory(new InMemoryStore()), self::failingLogger());
    }

    // Thrown again, because LiveTrends logs and swallows a failed render and a test would then only see a missing push.
    private static function failingLogger(): LoggerInterface
    {
        return new class extends AbstractLogger {
            #[Override]
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $failure = $context['exception'] ?? null;

                throw $failure instanceof Throwable ? $failure : new LogicException((string) $message);
            }
        };
    }

    private static function pages(Ledger $ledger, ReplayMarks $marks): LedgerReviewPages
    {
        $payloads = new LedgerPayloads(StoredSerializerFactory::create());

        return new LedgerReviewPages($ledger, new RoundPayloads($payloads), new RoundLedgerReading($ledger, $payloads, new ConfiguredPrices([])), new ChangeDecision(), new FailureInWords(), new FixedRoundEndings(), $marks);
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
