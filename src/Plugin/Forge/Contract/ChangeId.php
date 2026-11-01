<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

final readonly class ChangeId
{
    public function __construct(public string $value)
    {
        if ('' === trim($value)) {
            throw new ChangeIdEmpty('A change id is not empty.');
        }
    }
}
