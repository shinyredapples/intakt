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
- **Existing issue or pull request:** php/frankenphp#2479, named in plan T28 as the existing report (open, with a minimal reproduction and the root cause); php/frankenphp#2647 (open draft) is the likely fix.
- **Searches:** none beyond reading php/frankenphp#2479 and #2647 (titles and contents) in the 2026-11-06 refresh.
- **Decision:** `already reported, nothing to add` (php/frankenphp#2479, read by the owner on 2026-11-03). The thread already holds a framework-free reproduction, the root cause (Go keeps SIGHUP, SIGINT, SIGQUIT and SIGTERM unblockable, so the 1.12.x fix covers only the other signals), the aarch64 difference and reports up to v1.12.7; that v1.13.4 still fails would only be a "+1", so no comment is posted.
- **Refresh (2026-11-06, FrankenPHP v1.13.5):** still fails. The test run with the static x86_64 release binary of v1.13.5 (PHP 8.5.12 ZTS, Caddy v2.11.9) first on `PATH`: both `frankenphp php-cli` cases fail with `Failed asserting that 139 is identical to 0`, in flight and idle; the plain `php` cases pass. php/frankenphp#2479 ("php-cli queue:work segfaults (SIGSEGV) on SIGTERM/SIGINT graceful shutdown") already holds a minimal reproduction and the root cause; php/frankenphp#2647 (draft pull request, "Run Linux PHP CLI on a native thread with Go signals blocked") is the likely fix.
- **Refresh (2026-11-21, FrankenPHP v1.13.6):** Checked on 2026-11-21: still fails. The test run with the static x86_64 release binary of v1.13.6 (PHP 8.5.13 ZTS, Caddy v2.11.9) first on `PATH`: both `frankenphp php-cli` cases fail with `Failed asserting that 139 is identical to 0`, in flight and idle; the plain `php` cases pass. `php bin/console intakt:upstream:run` with the same `PATH`: `still fails  frankenphp-messenger-consume-sigterm`. The SIGURG change of v1.13.6 (php/frankenphp#2833, "fix: keep Go's preemption signal off the PHP threads", merged 2026-11-19) does not fix the crash. php/frankenphp#2479 is still open and php/frankenphp#2647 is still a draft, so the decision stays `already reported, nothing to add`, and the consumers and the Scheduler keep running with plain `php` (the image is still on 1.13.4).
- **Status:** nothing to post. The probe stays and shows when a FrankenPHP release fixes the crash, so the consumers can move to `frankenphp php-cli` (plan T28). Checked 2026-11-22: php/frankenphp#2479 is still open and php/frankenphp#2647 is still an open draft; FrankenPHP v1.14.0 came out on 2026-11-21 and the reproduction was not rerun on it for this check.
