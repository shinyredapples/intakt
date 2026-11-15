<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use Intakt\Core\Platform\Contract\FigureKind;

// The page's counts come from the forge, not from Intakt's ledger, so the line names its own source instead of FigureKind::source().
final readonly class FigureSource
{
    public function __construct(public FigureKind $kind, public string $from)
    {
    }

    public function line(): string
    {
        return ucfirst($this->kind->value).': '.$this->from.'.';
    }
}
