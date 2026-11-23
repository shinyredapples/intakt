<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Domain\TourCaption;
use Twig\Environment;

// On the shell topic, because the caption sits on every page and only the shell topic reaches them all.
final readonly class TourCaptionUpdates
{
    public const string TEMPLATE = 'dashboard/stream/tour_caption.stream.html.twig';
    public const string REFUSAL_TEMPLATE = 'dashboard/stream/demo_refused.stream.html.twig';

    public function __construct(
        private DemoTour $tour,
        private Environment $twig,
        private LiveUpdates $updates,
    ) {
    }

    public function publishTheStage(): void
    {
        $caption = $this->tour->caption();

        if (!$caption instanceof TourCaption) {
            return;
        }

        $this->updates->publish(new LiveEvent(DashboardTopics::SHELL, $this->twig->render(self::TEMPLATE, ['caption' => $caption])));
    }

    // On the shell topic, which the demo page listens on, aimed at the box of one Play so a refusal of a later Play never replaces the line of a run that is playing.
    public function publishTheRefusal(string $play, string $reason): void
    {
        $this->updates->publish(new LiveEvent(DashboardTopics::SHELL, $this->twig->render(self::REFUSAL_TEMPLATE, ['play' => $play, 'reason' => $reason])));
    }
}
