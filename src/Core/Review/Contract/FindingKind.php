<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

enum FindingKind: string
{
    case Security = 'security';
    case Logic = 'logic';
    case MissingTest = 'missing test';
    case TidyUp = 'tidy-up';
    case Other = 'other';
}
