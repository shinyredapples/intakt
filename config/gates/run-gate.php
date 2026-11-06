<?php

declare(strict_types=1);

use Intakt\Gates\CheckCommand;

// Because bin/check runs each gate in a child process of its own, this entry runs the one gate it names and prints its result line.
require dirname(__DIR__, 2).'/vendor/autoload.php';

// Because a PHP without its own php.ini prints warnings on stdout, where bin/check expects the result line alone.
ini_set('display_errors', 'stderr');

$arguments = $_SERVER['argv'] ?? [];
$arguments = is_array($arguments) ? array_values(array_filter($arguments, is_string(...))) : [];

exit(new CheckCommand(dirname(__DIR__, 2))->runGate($arguments[1] ?? '', array_slice($arguments, 2)));
