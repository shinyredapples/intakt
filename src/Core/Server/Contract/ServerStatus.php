<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Contract;

use NoDiscard;

interface ServerStatus
{
    /**
     * @throws AdminApiNotOnLocalhost
     */
    #[NoDiscard('a source that is down is only seen in the reading')]
    public function read(): ServerReading;
}
