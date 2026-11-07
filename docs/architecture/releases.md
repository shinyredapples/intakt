## Releases

`.github/workflows/release-labels.yml` labels each pull request `feat`, `fix` or `docs` from its title type and removes a stale one, reading the labels at run time; `.github/release.yml` groups the generated notes by those labels. `tests/Gates/ReleaseLabelsTest.php` runs the workflow script against a fake `gh`. Details: [Releasing](../releasing.md).
