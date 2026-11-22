# The Intakt user guide

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="../../assets/images/mascot/octopus-conducting-dark-144.webp">
    <img src="../../assets/images/mascot/octopus-conducting-light-144.webp" alt="The Intakt octopus, a green octopus holding a conductor's baton" width="96" height="96">
  </picture>
</p>

Your coding agent says it is done. Intakt tells you whether to believe it. This guide follows one developer, you, through a day with Intakt: from the first demo to a review your own agent runs on its own.

It is for developers who let an AI agent write code and want a second opinion before they merge. Each chapter takes three to five minutes.

## The cast

You will meet these characters on the dashboard. Each one stands where its job happens.

- <picture><source media="(prefers-color-scheme: dark)" srcset="../../assets/images/mascot/octopus-conducting-dark-144.webp"><img src="../../assets/images/mascot/octopus-conducting-light-144.webp" alt="The octopus with its baton" width="48" height="48"></picture> **The octopus.** Conducts. It runs the review and shows up when nothing else is happening.
- <picture><source media="(prefers-color-scheme: dark)" srcset="../../assets/images/characters/code_reviewer-dark.webp"><img src="../../assets/images/characters/code_reviewer-light.webp" alt="A meerkat with a magnifying glass" width="48" height="48"></picture> **The meerkat, code reviewer.** Reads your change and reports what is wrong with it.
- <picture><source media="(prefers-color-scheme: dark)" srcset="../../assets/images/characters/security_engineer-dark.webp"><img src="../../assets/images/characters/security_engineer-light.webp" alt="A hedgehog with a shield" width="48" height="48"></picture> **The hedgehog, security engineer.** Stands beside the meerkat while the security reviewer reads.
- <picture><source media="(prefers-color-scheme: dark)" srcset="../../assets/images/characters/judge-dark.webp"><img src="../../assets/images/characters/judge-light.webp" alt="An owl with a gavel" width="48" height="48"></picture> **The owl, judge.** Weighs every finding and makes one list of what to fix.
- <picture><source media="(prefers-color-scheme: dark)" srcset="../../assets/images/characters/developer-dark.webp"><img src="../../assets/images/characters/developer-light.webp" alt="A beaver with a wrench" width="48" height="48"></picture> **The beaver, developer.** Stands on the Fix step, while you or your agent fix the list.
- <picture><source media="(prefers-color-scheme: dark)" srcset="../../assets/images/characters/maintainer-dark.webp"><img src="../../assets/images/characters/maintainer-light.webp" alt="A tortoise with a toolbox" width="48" height="48"></picture> **The tortoise, maintainer.** Waits at Merge once a pass approves.

## The chapters

1. [Meet Intakt](01-meet-intakt.md): what one review does, and why you might trust it.
2. [The first five minutes](02-the-first-five-minutes.md): start Intakt and watch a recorded review, no key needed.
3. [Your first real review](03-your-first-real-review.md): add a key and watch the reviewers at work.
4. [Reading the verdict](04-reading-the-verdict.md): the owl's call, the list To fix, and what a finding tells you.
5. [Let your agent drive](05-let-your-agent-drive.md): Claude Code starts the review, fixes, and asks again.
6. [Trends and Status](06-trends-and-status.md): how your reviews go over time, and what runs right now.
7. [Keys, providers and costs](07-keys-providers-and-costs.md): whose models answer, and what a review costs.
8. [When something goes wrong](08-when-something-goes-wrong.md): the common surprises, why they happen, what to do.
9. [Running it for a team](09-running-it-for-a-team.md): your own panel, your own CI, and where the details live.

## Which tier a build runs on

When Intakt builds a task, these inputs decide its tier, the first that applies wins:

1. A path the build touches matches one of the project's sensitive paths: strong.
2. An escalation review confirmed a recurring P1 on the change's latest head: strong. A rejected escalation changes nothing.
3. An earlier round had a setback the project escalates on: strong.
4. A `model:<tier>` label on the task.
5. The project's default tier.

`intakt:build:tier <project> --change=<change>` shows the choice and its reason; no build lane passes the change yet ([details](../reference/user-manual.md#which-tier-a-build-starts-on)).

One thing to know about booting: Intakt can start a change in a scratch copy to see that it boots. A change that takes too long to install or to start counts as a failed boot, not as a fault of Intakt, so when main boots fine, the change can be sent back. Details are in the [complete reference](../reference/user-manual.md).

Looking for a setting, a command option or an exact rule? The [complete reference](../reference/user-manual.md) keeps every detail. The [plain-words guides](../guide/README.md) explain how Intakt works inside.

Next: [Meet Intakt](01-meet-intakt.md)
