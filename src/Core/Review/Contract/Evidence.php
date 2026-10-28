<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\Validator\Constraints\NotBlank;

final readonly class Evidence
{
    public function __construct(
        #[NotBlank]
        public string $probe,
        public string $output,
    ) {
    }
}
