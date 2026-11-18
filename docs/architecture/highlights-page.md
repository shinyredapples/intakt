# The published highlights page

`bin/highlights` assembles the highlights page from `docs/highlights.d/` and it is never committed (the `highlights` row of [Quality gates](quality-gates.md)). `.github/workflows/highlights.yml` publishes it from main. `bin/pages` assembles the same page with a relative link to the journey page beside it at `journey/` ([The Pages site](journey-page.md#the-pages-site)); the workflow does not call it yet.

**Decision:** GitHub Pages at `https://shinyredapples.github.io/intakt/`, through GitHub's Pages deployment (no `gh-pages` branch): a stable address, no sign-in, rebuilt from main on a push touching its inputs and once a day, unlike a release or CI artifact.

- Runs on a push to main touching its `paths`, daily and by hand (`gh workflow run highlights.yml`), never for a pull request. Both jobs have the guard `if: github.repository == 'shinyredapples/intakt' && github.ref == 'refs/heads/main'`.
- `build` has the read-only token, checks out the full history (fragments are ordered by the commit that added each), runs `bin/highlights` in the CI image as uid 1000, writes the page source as `docs/highlights.md` with each `{{` and `{%` written as a Liquid string that prints it (`{{ "{{" }}`, `{{ "{%" }}`, with `sed`), renders with `actions/jekyll-build-pages` and uploads with `actions/upload-pages-artifact`.
- `publish` runs no checkout and no repository code: its one step, `actions/deploy-pages`, deploys that artifact to the `github-pages` environment. Only it holds the `pages` write permission and the `id-token` permission.
- A failed run leaves the previous page. Runs are serialized (`concurrency` group `highlights`).

The owner sets the repository's Pages source to "GitHub Actions" once; until then `publish` fails. `tests/Gates/HighlightsWorkflowTest.php` pins the triggers, permissions, guard and `publish` job, and runs the step's write command on a stand-in `docs/highlights.md` to check the escaped openings, the unchanged rest and that a failed escape fails the step.

**Decision:** the page source escapes Liquid instead of turning it off. `actions/jekyll-build-pages` builds with the `github-pages` gem, which pins Jekyll 3.10 and Liquid 4.0.4; Jekyll 3.10 ignores `render_with_liquid: false` (a Jekyll 4 key) and runs Liquid on any page that holds `{{` or `{%`. Liquid prints each escaped string back before Markdown runs, so the published page shows a fragment's `{{` or `{%` as the fragment wrote it, and nothing restricts what fragments may contain. `README.md` (Documentation), `docs/guide/symfony-in-intakt.md` and the preamble in `docs/highlights.d/layout.md`, which the page itself carries, link to that address.
