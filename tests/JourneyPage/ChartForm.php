<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

enum ChartForm: string
{
    case StackedBars = 'stacked-bars';
    case GroupedBars = 'grouped-bars';
    case Line = 'line';
}
