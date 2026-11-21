## Symfony components

| Console `ConsoleEvents::ERROR` listener (`ConsoleErrorEvent::setExitCode()`) | `intakt:watchdog:check` exits with 2 also when the Console itself refuses its usage (an option without its value, a missing argument, an unknown option), so a caller tells refused input from a violation or a failed read (1) | in progress | T63 (#1790, part #1689), `src/Core/Watchdog/UseCase/CheckMain/ExitInvalidOnRefusedUsage.php` |
