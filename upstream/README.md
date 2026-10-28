# Upstream reproductions

One folder per likely bug in Symfony AI or the PHP MCP SDK: a minimal reproduction (a failing PHPUnit test; a script alone is always reported as `still fails`) and `REPORT.md` with the exact versions, what happens and what was expected.

Nothing here is posted upstream without the owner's approval. Each new pre-release is run against these reproductions with `php bin/console intakt:upstream:run` to see what was fixed; a reproduction is a folder of PHPUnit tests that assert the expected behavior. The gates skip this folder.
