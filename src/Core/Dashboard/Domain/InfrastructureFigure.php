<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class InfrastructureFigure
{
    public function __construct(
        public string $label,
        public string $value,
        public FigureState $state = FigureState::Fine,
        public ?string $downBecause = null,
    ) {
    }

    public static function down(string $label, string $reason): self
    {
        return new self($label, 'down', FigureState::Down, $reason);
    }
}
