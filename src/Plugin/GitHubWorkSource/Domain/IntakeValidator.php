<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'work_source_validator')]
final class IntakeValidator
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
