php/frankenphp#2479

Still exits with 139 (SIGSEGV) on SIGTERM on v1.13.5 (PHP 8.5.12, static x86_64 build): Symfony's `messenger:consume` under `frankenphp php-cli`, both with a message in flight and idle; plain `php` stops with 0. #2647 (PHP CLI on a native thread with Go signals blocked) looks like the fix.
