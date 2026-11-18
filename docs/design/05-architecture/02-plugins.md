# Plugins

Each plugin is a PHP class behind a small interface, shipped in the image, and selected in config.

| Kind | Interface (sketch) | v1 implementations |
|---|---|---|
| Forge | list work, read issue, open or update pull requests, read CI status, merge, comment, name the merged change that closed an issue (also in another repository) | GitHub, Gitea |
| Vendor | run build, run review, probe usage, price/limits | Claude Code (subscription or API key), Codex (subscription or API key), one API-only vendor (OpenAI-compatible endpoint) |
| Gate | command plus protected paths | project command (`bin/check`, `make check`, ...) |
| Language | compare two versions of a file without comments and whitespace, read a package manifest and lock file into the dependency policy's neutral form ([Merge](08-merge.md#mechanical-checks)), set the runtime memory limit of the processes a job starts ([Security model](../04-constraints.md#security-model)), say whether a change of a dependency is released and taken into the project's lock file | PHP (`PhpToken`, Composer), planned |
| Work source | which tasks are eligible, in what order | forge issues with a label, plus dependencies ("Waits for #n", and `Waits for release: <owner>/<repo>#<n>` for a change in another repository); see "Choosing work" |
| Notifier | deliver a notice (kind, item, link, urgency), report delivery, test | browser push (Web Push over HTTPS, from the dashboard as an installable app), Home Assistant (webhook or mobile app notify), ntfy, generic webhook, email |

Vendor plugins declare their models with a cost class and strengths (build, review, triage), so the planner can choose without vendor-specific code.

**A wait for another repository asks two questions.** A `Waits for release:` line ([Choosing work](03-choosing-work.md)) is lifted from two answers, never from a model: the forge plugin answers "which merged change closed this issue", and the language or deployment plugin answers "is that change in what the project runs" (released and taken into the lock file, for a dependency; deployed, for a tool Intakt runs on). A wait that no plugin can answer goes to the maintainer agent ([The maintainer agent](09-maintainer-agent.md)) and is never lifted by default.

**Notifications go through Intakt, never around it.** Every notice (an approval waiting, a question, an escalation, a halt, a failed update) is an event in the orchestrator, delivered by the notifier plugins the owner enabled: none of the loop's parts (jobs, the maintainer agent) send their own pushes. Routing is in the owner's config: which kinds go to which notifiers (for example approvals and halts to the phone, email as the fallback), quiet hours, and a fallback notifier when the first one fails. Each notice is sent once per item and state change, carries a link to the item on the dashboard or the forge (an approval notice also carries the exact head to approve), and its delivery result is recorded, so a notifier that fails is itself shown on the dashboard. Notices carry no secrets and no approval power: an approval the owner gives stays a forge action by the owner's account. Browser push needs the dashboard served over HTTPS (the wizard offers a certificate from the forge's or a local CA); a notifier's credentials (webhook URL, token) live in the owner's config like other credentials, and every notifier has a "send test" button in the wizard.
