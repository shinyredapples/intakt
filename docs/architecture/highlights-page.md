# The published highlights page

`bin/highlights` assembles the highlights page from `docs/highlights.d/` and it is never committed (the `highlights` row of [Quality gates](quality-gates.md)). `.github/workflows/highlights.yml` publishes it from main.

**Decision:** GitHub Pages at `https://shinyredapples.github.io/intakt/`, through GitHub's Pages deployment (no `gh-pages` branch): a stable address, no sign-in, always the latest main, unlike a release or CI artifact. Readers find it at that address.

- Runs on every push to main and by hand (`gh workflow run highlights.yml`), never for a pull request. Both jobs have the guard `if: github.repository == 'shinyredapples/intakt' && github.ref == 'refs/heads/main'`.
- `build` has the read-only token, checks out the full history (fragments are ordered by the commit that added each), runs `bin/highlights` in the CI image as uid 1000, renders with `actions/jekyll-build-pages` and uploads with `actions/upload-pages-artifact`.
- `publish` runs no checkout and no repository code: its one step, `actions/deploy-pages`, deploys that artifact to the `github-pages` environment. Only it holds the `pages` write permission and the `id-token` permission.
- A failed run leaves the previous page. Runs are serialized (`concurrency` group `highlights`).

The owner sets the repository's Pages source to "GitHub Actions" once; until then `publish` fails. `tests/Gates/HighlightsWorkflowTest.php` pins the triggers, permissions, guard and `publish` job. Links from `README.md`, the Symfony guide and the page's preamble are a separate change.
