<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use RuntimeException;

final class ScriptNotArmed extends RuntimeException
{
    public function __construct(string $file)
    {
        parent::__construct(sprintf('No scripted answers are armed in "%s": it needs a weight of zero or more seconds and a list of answers with a content type and a body.', $file));
    }
}
