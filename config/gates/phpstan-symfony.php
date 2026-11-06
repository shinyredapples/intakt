<?php

declare(strict_types=1);

use Intakt\Gates\ContainerDumpingGate;

return ['parameters' => ['symfony' => [
    'containerXmlPath' => dirname(__DIR__, 2).'/'.ContainerDumpingGate::CONTAINER_XML,
    // Because tests ask containers the dumped one cannot know (a test kernel, a locator, a service set at runtime), has() stays a plain bool, so a get() after it is accepted.
    'constantHassers' => false,
]]];
