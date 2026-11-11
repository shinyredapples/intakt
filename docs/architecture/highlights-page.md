# The published highlights page

The highlights page is assembled by `bin/highlights` from `docs/highlights.d/` and never committed (see the `highlights` row of [Quality gates](quality-gates.md)), so a reader of the repository cannot open it there. `.github/workflows/highlights.yml` publishes the assembly from main.

**Decision:** the page is published on GitHub Pages at `https://shinyredapples.github.io/intakt/`, through GitHub's own Pages deployment (no `gh-pages` branch). Chosen over a release or CI artifact because a Pages address is stable, needs no sign-in and always shows the latest main. The fragments stay the source: nothing the workflow writes reaches the repository.

How it runs:

- It starts on every push to main and by hand (`gh workflow run highlights.yml`), never on a pull request. Both jobs have the guard `if: github.repository == 'shinyredapples/intakt' && github.ref == 'refs/heads/main'`, so a copy of the repository does not publish and a manual run from another branch publishes nothing.
- The `build` job has the read-only token. It checks out the full history (the fragments are ordered by the commit that added each), runs `bin/highlights` in the CI image as uid 1000 on a read-only checkout, renders the page with `actions/jekyll-build-pages` and uploads it with `actions/upload-pages-artifact`.
- The `publish` job runs no repository code and no checkout: its one step, `actions/deploy-pages`, deploys the artifact `build` made to the `github-pages` environment. It alone holds the `pages` write permission and the `id-token` permission the deployment needs, and no `contents` write.
- A run that fails leaves the previous page in place. Runs are serialized (`concurrency` group `highlights`).

One step is the owner's: in the repository settings, the Pages source must be set to "GitHub Actions" once. Until then `publish` fails and no page is served.

`tests/Gates/HighlightsWorkflowTest.php` pins the triggers, the permissions, the guard and that `publish` runs no repository code.

How readers find it: the address above. Links to it from the `README.md`, the Symfony guide and the page's own preamble are a separate change.
