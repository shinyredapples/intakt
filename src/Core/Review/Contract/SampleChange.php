<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Named in the Contract, because the dashboard's first-run page starts the review of the sample change that the seed builds.
final readonly class SampleChange
{
    public const string PROJECT = 'sample';

    public const string CHANGE = 'sample-change';

    public const string BASE = 'main';

    public const string TASK = 'Add a shipping cost by weight and a report of a customer\'s orders; a cart of 100 or more keeps its discount.';
}
