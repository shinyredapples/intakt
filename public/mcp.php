<?php

declare(strict_types=1);

// A second entry file, because FrankenPHP refuses two workers on one file and the mcp worker needs its own threads.
return require __DIR__.'/index.php';
