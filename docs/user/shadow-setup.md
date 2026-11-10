# Setting up a shadow install

A shadow install runs Intakt next to an existing review process on your own host. Every project is in shadow mode: Intakt reviews and records verdicts but never writes to a forge, and none of its verdicts counts toward a merge. This page puts the pieces in order. Each step uses something Intakt already has; what is not built yet is listed at the end.

## 1. Run the image in its own container

Build the image and run it as in [Running the container image](README.md#running-the-container-image), with `--restart unless-stopped` and the dashboard published on `127.0.0.1` only.

The host side is yours, and Intakt's image does not change it:

- **Nesting.** The container runs on a host where Docker can run inside it (a system-container runtime, or a virtual machine or LXC container with nesting switched on). Intakt itself needs no Docker; the nesting is for the checks and tools the install runs beside it.
- **Fenced from the local network.** Put the container on its own Docker network and add host firewall rules that drop its traffic to your local network's ranges (`<lan-range>`) while leaving the internet open. Intakt needs the model providers' hosts and, later, the forge. Check the fence from inside: `docker exec intakt curl -m 5 http://<lan-host>/`, with a machine on your local network as `<lan-host>`, must time out or be refused. If it prints a page, the fence is open.

## 2. Put both projects in shadow mode

Copy `config/intakt.example.yaml` to `config/intakt.yaml`, define each project with its `tiers`, `review` panel and `watch` section, and set `mode: shadow` on every one (see [Shadow mode](README.md#shadow-mode) and [Capability tiers per project](README.md#capability-tiers-per-project)). Mount the file and set `INTAKT_CONFIG` to its path inside the container, or bake it into your own image. Then run `php bin/console cache:clear`.

## 3. Add the logins

Put the secrets in the `intakt.env` file you pass with `--env-file`. Never commit it.

- **Claude subscription.** Create a token with `claude setup-token` and set `CLAUDE_CODE_OAUTH_TOKEN` (and `CLAUDE_CODE_OAUTH_TOKEN_<ACCOUNT>` for each further account listed under `accounts:`). Map the tiers that use it to `provider: claude_code` ([A reviewer on a Claude subscription](README.md#a-reviewer-on-a-claude-subscription)). The `claude` tool must be installed where Intakt runs.
- **Codex subscription.** Log in with `codex` once, copy its `auth.json` to `var/codex/logins/<account>/auth.json` in the data volume, make it readable by Intakt's user only, and pin `codex: { model: ..., effort: ... }` ([A Codex subscription login](README.md#a-codex-subscription-login)).

## 4. Set the shadow ceiling

`budget.shadow_ceilings` holds shadow rounds back until the accounts are below a lower ceiling than live work (70 percent for both windows by default), so shadow work leaves capacity to the loop you already run:

```yaml
budget:
    shadow_ceilings:
        five-hour: 70
        weekly: 70
```

Nothing records readings of the windows yet, so until it does the shadow ceilings hold nothing back.

## 5. Check the install

1. **The install answers.** `docker exec intakt php bin/console intakt:server:status` prints the worker threads and `hub: healthy`, and `docker inspect --format '{{.State.Health.Status}}' intakt` prints `healthy`. Open `http://localhost:7781/projects` on the host.
2. **Both projects list their panels.** For each project, `docker exec intakt php bin/console intakt:review:panel <project>` prints the quorum and one line per reviewer, and `intakt:ai:tiers` shows the provider and model of every tier. Neither calls a model.
3. **A tick runs.** `docker exec intakt php bin/console messenger:consume scheduler_watcher --limit=1` runs one tick of the watcher. The scheduler ticks once a minute, so the command waits for the next minute's tick before it runs it and exits. Until a forge reads pull requests from GitHub, the tick fails with a clear message saying so: that is the state of the code, not of your install.

## 6. Read the shadow report

`php bin/console intakt:ledger:events` shows the verdicts, each marked `"mode":"shadow"`, and `shadow_round_held` events when a ceiling held a round back. The dashboard marks shadow verdicts `(shadow)` on each review page ([The dashboard](README.md#the-dashboard)). Compare them with what your existing process decided for the same change.

## Not built yet

- **Home Assistant notices.** The notice contract exists but no notifier is built, so the install sends no notice to Home Assistant. The Home Assistant notifier, pushing notices for owner actions only, arrives with #349. You will configure it alongside the project settings of step 2 and the secrets of step 3; no setting for it exists yet.
- **Reading pull requests from GitHub.** Until a forge does, the watcher cannot start rounds by itself; a round for a branch of a local repository starts with `intakt:review`.
- **Running the panel on every new head** of a shadow project is planned.
