<?php

declare(strict_types=1);

use Intakt\Gates\ContainerDumpingGate;

return ['parameters' => ['symfony' => [
    'containerXmlPath' => ContainerDumpingGate::containerXml(dirname(__DIR__, 2)),
    // Because tests ask containers the dumped one cannot know (a test kernel, a locator, a service set at runtime), has() stays a plain bool, so a get() after it is accepted.
    'constantHassers' => false,
]]];
