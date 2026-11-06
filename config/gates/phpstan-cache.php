<?php

declare(strict_types=1);

use Intakt\Gates\GatesCache;

return ['parameters' => ['tmpDir' => GatesCache::directory().'/phpstan']];
