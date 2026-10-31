<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

enum Bus: string
{
    case Command = 'command.bus';
    case Query = 'query.bus';
}
