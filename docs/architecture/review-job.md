# Review job

Plan task T58, part 1 (#1861; design: [reviews](../design/05-architecture/07-reviews.md), [threat model](../design/05-architecture/14-threat-model.md#reviewer-every-configured-role-specialists-included)). A review job runs one reviewer's CLI in a [job container](job-containers.md) against a read-only checkout of the exact head, reaching only its vendor through the job's [egress proxy](job-containers.md#egress). Built so far: the job's command `docker/job/review.sh` and the review job image ([Job images](job-images.md#the-review-job-image)). Nothing starts a review job yet: the job, its inputs and allowlist, reading its answer and the choice between a review job and the in-process [reviewer agent](reviewer-agent.md) arrive with later tasks.

## In the container

`docker/job/review.sh <checkout> <head> <prompt file> <cli> <model>` (POSIX `sh`, with `git` and the CLI) runs as the job's command after the [start check](job-containers.md#start-check). From its start git reads no global or system settings (`GIT_CONFIG_GLOBAL=/dev/null`, `GIT_CONFIG_NOSYSTEM=1`). It writes `review.log` into the output directory and checks, in this order, before the CLI runs; the first check that fails writes its reason to `review.log` and ends the job with its exit code, and the CLI does not run:

| Check | Refused when | Exit |
|---|---|---|
| the CLI | `<cli>` is neither `claude` nor `codex` (`unknown reviewer CLI: <cli>`) | 64 |
| full mode | `INTAKT_REDUCED_MODE` is set and not empty: a start check failed, so the checkout or the vendor may not be what the job was told (`the start check failed (<names>), so the review does not run`) | 4 |
| the head | `<head>` is not 40 or 64 lowercase hex digits (`<head> is not a full commit name`), so a branch or a short name never counts; the checkout has no `.git` of its own (git would otherwise read a parent folder's repository); `git rev-parse --verify --end-of-options HEAD^{commit}` in the checkout is another commit (`the checkout is at <commit>, not at <head>`); `git --no-optional-locks -c core.fsmonitor=false status --porcelain --untracked-files=all` fails or lists a change or an untracked file (`the checkout differs from <head>`); `git ls-files -v` fails or lists a file marked skip-worktree or assume-unchanged, which git status takes as unchanged without reading it (`<file> is marked so that git status does not compare it`); ignored files such as `vendor/` do not count | 1 |
| read-only | an empty file `.intakt-review-probe` can be created in the checkout or in its `.git` folder (removed again at once): `<folder> can be written, so the checkout is not read-only` | 2 |
| the proxy alone | `INTAKT_JOB_EGRESS` is unset or empty (`the job has no egress allowlist, ...`), or one of `HTTPS_PROXY`, `https_proxy`, `HTTP_PROXY`, `http_proxy` is not exactly `http://egress-proxy:3128`, the address `EgressProxy` gives every job with an allowlist (`<name> is not the job's proxy http://egress-proxy:3128`) | 3 |

Then `NO_PROXY`, `no_proxy`, `ALL_PROXY` and `all_proxy` are unset, because an image can set them and a host named there would be tried past the proxy. In the checkout, with the prompt file on its standard input, its standard output to `answer` in the output directory and its errors to `review.log`, it runs:

- `claude -p --model <model> --output-format json --tools Read,Glob,Grep --setting-sources '' --strict-mcp-config --no-session-persistence`, with `CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC=1`: only the CLI's read-only file tools, no project or user settings and no MCP server;
- `codex exec --json --model <model> --sandbox read-only --ephemeral --skip-git-repo-check --ignore-user-config --ignore-rules --disable <feature> ... -c web_search="disabled" -`, with one `--disable` for each tool the in-process Codex reviewer switches off (`IsolatedCodexCli::DISABLED_FEATURES`: shell and command execution, apps, plugins, browsers, computer use, other agents, image tools, hooks). The read-only sandbox stops writes, not commands, so the switched-off shell is what keeps the CLI from running the code under review.

It then logs `<cli> exited with <status>`. As the container's first process (PID 1) it sends `SIGKILL` to every other process of the job (`kill -s KILL -1`), so nothing the reviewer left running can still write the answer. It exits with 0 whatever the CLI's status, which `review.log` holds. `HOME` and `TMPDIR` are the job's own tmpfs work area.

The checks are the script's own; the guarantees come from the container: the checkout is a read-only input mount and the job's only network is its internal network with the proxy ([Job containers](job-containers.md#the-container)). The script refuses to run the reviewer when either is not what it sees.

## Tests

`tests/Core/Review/ReviewScriptTest.php` runs the real `docker/job/review.sh` with `sh -c` on a temporary repository whose write permission is taken away (skipped as root, who ignores it), with scripted `claude` and `codex` on the path that record their arguments, environment and folder: each refusal above with its exit code and no CLI run, the arguments of both CLIs, the prompt on the CLI's input, the proxy exceptions removed, a failing CLI that still ends the script with 0, and that the script's proxy address is `EgressProxyDefinition::ALIAS` and `PORT`. No review job has run against a live engine yet.
