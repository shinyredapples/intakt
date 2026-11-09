<?php

declare(strict_types=1);

use Intakt\Gates\ContainerDumpingGate;

return ['parameters' => ['symfony' => [
    // Because the gate runs PHPStan in the tree it analyses, the dump is read from that tree, where its console wrote it.
    'containerXmlPath' => ContainerDumpingGate::containerXml((string) getcwd()),
    // Because phpstan-symfony would type has() on a service locator against container ids, a locator's own key would read as always false; ContainerUnknownHasIdRule reports an unknown container id instead.
    'constantHassers' => false,
]]];
