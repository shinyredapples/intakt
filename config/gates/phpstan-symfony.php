<?php

declare(strict_types=1);

use Intakt\Gates\ContainerDumpingGate;

return ['parameters' => ['symfony' => [
    'containerXmlPath' => ContainerDumpingGate::containerXml(dirname(__DIR__, 2)),
    // Because phpstan-symfony would type has() on a service locator against container ids, a locator's own key would read as always false; ContainerUnknownHasIdRule reports an unknown container id instead.
    'constantHassers' => false,
]]];
