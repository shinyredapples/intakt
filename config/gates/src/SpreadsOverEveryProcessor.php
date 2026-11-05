<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because a gate whose tool starts its own worker on every processor starves the other such gate when both run at once.
interface SpreadsOverEveryProcessor extends Gate
{
}
