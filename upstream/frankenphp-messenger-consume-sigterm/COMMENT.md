php/frankenphp#2479

A Symfony reproduction on v1.13.4 (PHP 8.5.12, ZTS image `dunglas/frankenphp:1.13.4-php8.5`).

`bin/console messenger:consume async`, started as `frankenphp php-cli bin/console messenger:consume async` (symfony/messenger 8.1 with a Doctrine transport on SQLite), exits with 139 (SIGSEGV) when it receives SIGTERM. Messenger stops the worker through `pcntl_signal(SIGTERM, ...)` with async signals. The crash happens with a message in flight and with an idle consumer (the reproduction has a test case for each). The same command under plain `php` stops with exit code 0.

Steps: dispatch one message, start the consumer, send SIGTERM once the handler runs, let the handler finish. The test forces that order with marker files (`MessengerConsumeSigtermTest.php` and `console.php`, one kernel, no other service). Expected: exit code 0, the message in flight finished or requeued.
