<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

use Attribute;

// Marks service state that must outlive a reset on purpose, so a reviewer sees it on the property and the stateful-service gate lets it pass.
#[Attribute(Attribute::TARGET_PROPERTY)]
final class KeptForTheProcess
{
}
