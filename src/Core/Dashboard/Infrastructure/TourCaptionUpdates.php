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

    // On the shell topic too, because the demo page that pressed Play listens on it and a replay refused in the worker has no response to carry the reason.
    // Aimed at the box of one Play, because a refusal of a later Play must not replace the start line of a run that is playing.
    public function publishTheRefusal(string $play, string $reason): void
    {
        $this->updates->publish(new LiveEvent(DashboardTopics::SHELL, $this->twig->render(self::REFUSAL_TEMPLATE, ['play' => $play, 'reason' => $reason])));
    }
}
