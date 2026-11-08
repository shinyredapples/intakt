<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;

interface Prices
{
    public function costOf(?string $model, TokenFigures $figures): Cost;
}
