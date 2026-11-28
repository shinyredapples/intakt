<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Budget\Contract\LeftOutCause;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;

#[Exclude]
final readonly class PickedLeftOut
{
    public function __construct(
        public string $account,
        public LeftOutCause $cause,
        // One fixed format on both sides, because a free-form date such as "tomorrow" would read back as a different reset each time.
        #[Context([DateTimeNormalizer::FORMAT_KEY => DATE_ATOM])]
        public ?DateTimeImmutable $resetsAt,
    ) {
    }
}
