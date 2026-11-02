# Upstream reproductions

One folder per likely bug in a library Intakt uses (Symfony AI, the PHP MCP SDK, Symfony components, Doctrine): a minimal reproduction (PHPUnit tests, or a script named `reproduce.php`) and `REPORT.md` with the exact versions, what happens and what was expected.

Each `REPORT.md` ends with `## Upstream status`: the result at the pinned versions, the result on the upstream development branch (run in a scratch copy, never through Intakt's own `composer.json`), the upstream issues and pull requests found (or "none found", with the searches made), and the decision: `already reported`, `fixed upstream`, `ready to file` or `not a bug`.

Nothing here is posted upstream without the owner's approval. Each new pre-release is run against these reproductions with `php bin/console intakt:upstream:run` to see what was fixed; a reproduction checks the expected behavior. PHPUnit tests assert it. A `reproduce.php` script (run from its folder, with the project's autoloader loaded) exits with 0 and prints `REPRODUCTION PASSES` as its last line only when the expected behavior held; anything else counts as `still fails`. A folder with `reproduce.php` is run as a script, never as PHPUnit tests. The gates skip this folder.
