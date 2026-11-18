# The published highlights page

`bin/highlights` assembles the highlights page from `docs/highlights.d/` and it is never committed (the `highlights` row of [Quality gates](quality-gates.md)). `.github/workflows/highlights.yml` publishes it from main. `bin/pages` assembles the same page with a relative link to the journey page beside it at `journey/` ([The Pages site](journey-page.md#the-pages-site)); the workflow does not call it yet.

**Decision:** GitHub Pages at `https://shinyredapples.github.io/intakt/`, through GitHub's Pages deployment (no `gh-pages` branch): a stable address, no sign-in, always the latest main, unlike a release or CI artifact. Readers find it at that address.

- Runs on every push to main and by hand (`gh workflow run highlights.yml`), never for a pull request. Both jobs have the guard `if: github.repository == 'shinyredapples/intakt' && github.ref == 'refs/heads/main'`.
- `build` has the read-only token, checks out the full history (fragments are ordered by the commit that added each), runs `bin/highlights` in the CI image as uid 1000, writes the page source as a front matter block holding `render_with_liquid: false` followed by `docs/highlights.md` unchanged (so Jekyll publishes a `{{` or `{%` in a fragment verbatim instead of running Liquid on it), renders with `actions/jekyll-build-pages` and uploads with `actions/upload-pages-artifact`.
- `publish` runs no checkout and no repository code: its one step, `actions/deploy-pages`, deploys that artifact to the `github-pages` environment. Only it holds the `pages` write permission and the `id-token` permission.
- A failed run leaves the previous page. Runs are serialized (`concurrency` group `highlights`).

The owner sets the repository's Pages source to "GitHub Actions" once; until then `publish` fails. `tests/Gates/HighlightsWorkflowTest.php` pins the triggers, permissions, guard and `publish` job, and runs the step's write command on a stand-in `docs/highlights.md` to check the front matter and the unchanged content. `README.md` (Documentation), `docs/guide/symfony-in-intakt.md` and the preamble in `docs/highlights.d/layout.md`, which the page itself carries, link to that address.
