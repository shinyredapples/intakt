<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

new Dotenv()->bootEnv(__DIR__.'/../.env');

// Dropped, because with APP_DEBUG=0 no kernel checks its container for freshness, so a build directory the caller shares would hand each worker, and each APP_CACHE_DIR a test moves to, the container compiled there first.
unset($_SERVER['APP_BUILD_DIR'], $_ENV['APP_BUILD_DIR']);
putenv('APP_BUILD_DIR');
