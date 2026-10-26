<?php

declare(strict_types=1);

$cacheBase = getenv('INTAKT_GATES_CACHE');
$cacheDirectory = (is_string($cacheBase) && '' !== $cacheBase ? $cacheBase : sys_get_temp_dir().'/intakt-gates').'/phpstan';

return ['parameters' => ['tmpDir' => $cacheDirectory]];
