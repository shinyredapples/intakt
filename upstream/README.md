# Upstream reproductions

One folder per likely bug in Symfony AI or the PHP MCP SDK: a minimal reproduction (PHPUnit tests, or a script named `reproduce.php`) and `REPORT.md` with the exact versions, what happens and what was expected.

Nothing here is posted upstream without the owner's approval. Each new pre-release is run against these reproductions with `php bin/console intakt:upstream:run` to see what was fixed; a reproduction checks the expected behavior. PHPUnit tests assert it. A `reproduce.php` script (run from its folder, with the project's autoloader loaded) exits with 0 and prints `REPRODUCTION PASSES` as its last line only when the expected behavior held; anything else counts as `still fails`. A folder with `reproduce.php` is run as a script, never as PHPUnit tests. The gates skip this folder.
