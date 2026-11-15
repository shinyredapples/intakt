<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;

final readonly class DailyChart
{
    /**
     * @param list<DateTimeImmutable> $days
     * @param list<ChartSeries>       $series
     */
    public function __construct(
        public string $name,
        public string $title,
        public ChartForm $form,
        public array $days,
        public array $series,
        public FigureSource $source,
    ) {
    }

    // The drawing, its alt text and its table carry the same marker, so a reader of any one of them knows the kind.
    public function caption(): string
    {
        return $this->labeled($this->title);
    }

    public function labeled(string $name): string
    {
        return $name.' ('.$this->source->kind->value.')';
    }
}
