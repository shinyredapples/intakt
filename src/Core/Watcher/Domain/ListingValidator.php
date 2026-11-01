<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'watcher_listing_validator')]
final class ListingValidator
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        private readonly string $project,
        #[ORM\Column(type: 'text')]
        private string $validator,
    ) {
    }

    public function project(): string
    {
        return $this->project;
    }

    public function value(): string
    {
        return $this->validator;
    }

    public function replace(string $validator): void
    {
        $this->validator = $validator;
    }
}
