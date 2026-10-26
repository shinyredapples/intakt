# Plugins

Each plugin is a PHP class behind a small interface, shipped in the image, and selected in config.

| Kind | Interface (sketch) | v1 implementations |
|---|---|---|
| Forge | list work, read issue, open or update pull requests, read CI status, merge, comment | GitHub, Gitea |
| Vendor | run build, run review, probe usage, price/limits | Claude Code (subscription or API key), Codex (subscription or API key), one API-only vendor (OpenAI-compatible endpoint) |
| Gate | command plus protected paths | project command (`bin/check`, `make check`, ...) |
| Work source | which tasks are eligible, in what order | forge issues with a label, plus dependencies ("Waits for #n"); see "Work intake" |
| Notifier | deliver a notice (kind, item, link, urgency), report delivery, test | browser push (Web Push over HTTPS, from the dashboard as an installable app), Home Assistant (webhook or mobile app notify), ntfy, generic webhook, email |

Vendor plugins declare their models with a cost class and strengths (build, review, triage), so the planner can choose without vendor-specific code.

**Notifications go through Intakt, never around it.** Every notice (an approval waiting, a question, an escalation, a halt, a failed update) is an event in the orchestrator, delivered by the notifier plugins the owner enabled: none of the loop's parts (jobs, the maintainer agent) send their own pushes. Routing is in the owner's config: which kinds go to which notifiers (for example approvals and halts to the phone, digests by email), quiet hours, and a fallback notifier when the first one fails. Each notice is sent once per item and state change, carries a link to the item on the dashboard or the forge (an approval notice also carries the exact head to approve), and its delivery result is recorded, so a notifier that fails is itself shown on the dashboard. Notices carry no secrets and no approval power: approving stays a forge action by the owner's account. Browser push needs the dashboard served over HTTPS (the wizard offers a certificate from the forge's or a local CA); a notifier's credentials (webhook URL, token) live in the owner's config like other credentials, and every notifier has a "send test" button in the wizard.
