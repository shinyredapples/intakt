<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

// The container ids and parameter names of this module, so config, code and tests never spell one twice.
final readonly class BudgetServices
{
    public const string CEILINGS = 'intakt.budget.ceilings';
    public const string PRICES = 'intakt.budget.prices';
}
