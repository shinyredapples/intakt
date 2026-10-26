# New and existing projects

Both are first-class. The difference is where the toolchain, the check command and the gates come from: an existing codebase already has them, while a new project must get them before any feature is built.

## Existing codebases

Detection runs in the wizard and again whenever main changes a manifest. It reports what it found and how sure it is, and the owner confirms or edits each item.

| Source | What it gives |
|---|---|
| Manifests and lock files: composer.json, package.json (npm, pnpm, yarn, bun from the lock file), go.mod, pyproject.toml / requirements / uv.lock / poetry.lock, Cargo.toml, Gemfile, pom.xml / build.gradle, *.csproj, mix.exs | languages, package managers, versions (from `config.platform`, `engines`, `.tool-versions`, `.nvmrc`, `go` directive, `requires-python`, rust-toolchain) |
| `.devcontainer/devcontainer.json`, `Dockerfile`, `compose.yaml` | the job image, used as is when present |
| CI config: `.github/workflows`, `.gitea/workflows`, `.gitlab-ci.yml` | the commands CI already runs, and the services it starts (databases, caches) |
| Task runners: `Makefile`, `justfile`, `Taskfile.yml`, composer and npm `scripts`, `bin/check` | the check command, preferring one that already bundles lint, static analysis and tests |
| Agent instructions: `AGENTS.md`, `CLAUDE.md`, `.cursor/rules`, `CONTRIBUTING.md` | conventions passed to every job; listed as protected paths |

Rules:
- Monorepos get one entry per package root, each with its own toolchain and check; the project check runs them all.
- The check is proven before use: Intakt runs it on main in a check job. If main is red, it says so and does not start building, because no build could pass.
- No tests or no check at all is a finding, not an error. Intakt proposes a minimal check (the project's own linters and test runner, if any) and, optionally, a first task: "add a check command and CI".
- Services the tests need (MySQL, Postgres, Redis, ...) come from the CI config or compose file and run as sidecars of the check job.

## New projects from scratch

An empty repository starts with a foundation phase before any feature work:
1. **Spec.** The owner describes the project (a few sentences or a full spec) and picks a stack, or lets Intakt propose one from the spec.
2. A foundation build creates the skeleton: the manifest and lock file, the formatter, linter, static analysis and test runner, a check command, CI for the chosen forge, `AGENTS.md` with the conventions, and a job image definition.
3. The foundation goes through review like any change, and then to the owner. The gates it creates become protected paths, so the owner approves them once, explicitly. From then on, no builder can weaken them.
4. **Plan.** Intakt turns the spec into a plan: milestones and tasks with dependencies, which the owner edits and approves; the tasks are then created on the forge and the loop starts on them.

Stack templates (for example Symfony, Laravel, Next.js, Go service, Python package) are optional starting points. Without one, the foundation build chooses tools and the owner approves them.

## Dependent services and projects

A project often needs more than itself to build and test: a database, a cache, and often other backends (an auth service, a billing API, a sibling microservice). Intakt supports three ways to provide each dependency, chosen per dependency:

| Mode | What runs | When to use |
|---|---|---|
| **Bundled** (default) | The dependency runs as a sidecar container on a private network created for the job, from its image, its repo (built from its devcontainer, Dockerfile or compose file at a pinned ref), or a registry image. | Anything that can run in a container. Each job gets its own fresh copy, so parallel builds never share state. |
| **External** | Jobs connect to an instance that is already running (a staging backend, a shared dev server). Intakt adds its address to the job's egress allowlist and injects its credentials. | Dependencies that are too heavy or not containerizable, or a shared environment the team already maintains. |
| **Stub** | A mock server generated from the dependency's contract (OpenAPI, gRPC proto, recorded fixtures). | Third-party APIs, or when neither of the others is practical. |

Bundled details:
- **Sources.** Declared in the project config, or detected from `compose.yaml` services, CI service containers, and `*_URL` / `*_HOST` variables in `.env.example`. A dependency that is another Intakt project points at that project, and Intakt reuses its job image.
- **Version.** Each dependency is pinned to a ref: its main (test against the latest), a release tag, or a commit. Following main is the default for projects that Intakt also builds.
- **Data.** An optional seed step per dependency (migrations, fixtures, a SQL dump) runs before the job's tests. Readiness is a health check, not a sleep.
- **Cost.** Images are built once per ref and cached. Sidecars start only for jobs that need them (check and review, and builds whose tests need them), with memory and CPU limits counted in the slot's budget. A warm pool per slot, reset between jobs, is a later optimization.

External details:
- **Isolation is the owner's responsibility,** and the wizard says so. Parallel builds and reviews hit the same instance, so tests must use their own tenant, namespace or prefix, or only read. Intakt passes a per-job id (`INTAKT_JOB_ID`) that tests can use for that.
- **Credentials** are stored in the orchestrator and injected only into the job types the owner allows. By default that is check and review jobs. Build jobs get them only if the owner opts in, because a build job runs model-written code. Test-scoped, low-privilege credentials are recommended.

Projects that change together:
- When several dependent projects are all built by Intakt, a change in one can need a change in another. The loop links tasks across projects ("Waits for billing#12") and builds them in order.
- When a dependency's main moves, dependents re-check against the new version. A dependent that turns red gets a task in its own queue.
- Contract tests are encouraged: they catch a breaking change in the dependency's own loop, before its dependents fail.

## Projects change

A new project grows into an existing one. When main changes a manifest, lock file, devcontainer or CI config, Intakt re-runs detection, rebuilds the job image, and re-proves the check on main before the next build starts. A changed check command or a new language goes to the owner as a protected change.
