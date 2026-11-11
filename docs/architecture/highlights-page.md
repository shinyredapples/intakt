# The published highlights page

The highlights page is assembled by `bin/highlights` from `docs/highlights.d/` and never committed (see the `highlights` row of [Quality gates](quality-gates.md)), so a reader of the repository cannot open it there. `.github/workflows/highlights.yml` publishes the assembly from main.

**Decision:** the page is published to GitHub Pages, served from the `gh-pages` branch, at `https://shinyredapples.github.io/intakt/` (the page is `index.md`, which Pages renders as HTML). Chosen over a release or CI artifact because a Pages address is stable, needs no sign-in and always shows the latest main; chosen over a site build action because the branch needs no OIDC token and no extra permission beyond writing one branch. The fragments stay the source: nothing the workflow writes reaches main.

How it runs:

- It starts on every push to main and by hand (`gh workflow run highlights.yml`), never on a pull request, and only in `shinyredapples/intakt` (a copy does not publish).
- The `build` job has a read-only token. It checks out the full history (the fragments are ordered by the commit that added each), runs `bin/highlights` in the CI image as uid 1000 on a read-only checkout, and uploads the page as an artifact.
- The `publish` job has `contents: write` and runs nothing from the repository: it downloads the artifact, makes one commit holding only `index.md` in a new repository, and force-pushes it to `gh-pages`, so the branch always holds exactly one commit and one file.
- A run that fails leaves the previous page in place. Runs are serialized (`concurrency` group `highlights`).

One step is the owner's: in the repository settings, Pages must serve from the `gh-pages` branch, root folder. Until then the branch exists but no page is served.

How readers find it: the address above. Links to it from the `README.md`, the Symfony guide and the page's own preamble are a separate change.
