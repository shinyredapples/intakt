## Symfony components

| Console `#[Option]` on a backed enum parameter of an invokable command | `intakt:status --format=json` takes its format as the enum `StatusFormat`, so the Console resolves the value and refuses any format the enum does not list before the command runs | in progress | T68 (#350, part #1701), `src/Core/Dashboard/UseCase/ShowStatus/ShowStatusCommand.php` |
