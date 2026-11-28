<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use InvalidArgumentException;

// Its own class, because the container build catches exactly this refusal and lets any other failure pass.
final class BuildLimitsRefused extends InvalidArgumentException
{
}
