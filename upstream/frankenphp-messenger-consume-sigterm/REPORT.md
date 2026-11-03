# FrankenPHP: `messenger:consume` crashes on SIGTERM under `frankenphp php-cli`

## Versions

- FrankenPHP v1.13.4 (Caddy v2.11.7), PHP 8.5.12 (`pcntl` and `pdo_sqlite` loaded), the same version as the `dunglas/frankenphp:1.13.4-php8.5` base image
- symfony/messenger v8.1.10 with a Doctrine transport on SQLite

## Reproduction

`MessengerConsumeSigtermTest.php` runs `console.php`, a small kernel with FrameworkBundle, DoctrineBundle and one handler. The test dispatches one message, starts `messenger:consume async` (no limit, so only SIGTERM stops it), waits until the handler is in flight (a marker file), sends SIGTERM, releases the handler and reads the exit code and the transport. Plain `php` runs the same scenario as the control.

Run: `vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php upstream/frankenphp-messenger-consume-sigterm`

## Actual

- Plain `php`: exit code 0, the message is handled.
- `frankenphp php-cli`: exit code 139 (SIGSEGV) after SIGTERM, with the handler in flight and also with an idle consumer (no message at all; the test case `testTheIdleConsumerStopsWithExitCodeZeroOnSigterm`). The message in flight is still handled before the crash in the test, so nothing is lost, but a supervisor sees a crash.

## Expected

A clean stop with exit code 0 after the message in flight is finished or requeued.

## Effect in Intakt

The consumers and the Scheduler run with plain `php` in the image (plan T22, #28), not with `frankenphp php-cli`, as the plan says until this passes.

## Upstream status

Checked on 2026-11-03.

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run`: `still fails  frankenphp-messenger-consume-sigterm`, the exit-code case under `frankenphp php-cli` fails with `Failed asserting that 139 is identical to 0`.
- **Development branch:** not checked (no FrankenPHP development build in the sandbox); the owner can run the test against a newer build.
- **Existing issue or pull request:** php/frankenphp#2479, named in plan T28 as the existing report; the sandbox has no forge access, so its text was not read.
- **Searches:** none made (no network beyond the package registries).
- **Decision:** `already reported` (php/frankenphp#2479). The comment in `COMMENT.md` adds the Symfony reproduction.
- **Status:** `COMMENT.md` is ready; the owner approves it before it is posted.
