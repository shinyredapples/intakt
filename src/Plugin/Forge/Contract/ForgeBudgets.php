<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

interface ForgeBudgets
{
    // From what the forge's answers last reported, so showing the budget never spends any of it.
    /**
     * @return list<ForgeBudget>
     */
    public function thisHour(): array;
}
