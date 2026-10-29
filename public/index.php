<?php

declare(strict_types=1);

use Intakt\Kernel;

require_once __DIR__.'/../vendor/autoload_runtime.php';

return static function (array $context): Kernel {
    $environment = $context['APP_ENV'];
    assert(is_string($environment));

    return new Kernel($environment, filter_var($context['APP_DEBUG'], FILTER_VALIDATE_BOOLEAN));
};
