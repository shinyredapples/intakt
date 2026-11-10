# Glossary

The words used across Intakt's guides and dashboard, in plain terms. Each entry links to the guide that explains it best.

| Word | Meaning | More |
|---|---|---|
| **Acceptance** | the checkable results that say a task is done | [writing a good task](writing-a-good-task.md) |
| **Advisory reviewer** | a specialist whose notes become follow-ups but never block | [how it works](how-intakt-works.md#which-reviewers-look-at-a-change) |
| **Approval** | your OK, from your own account, naming one exact version | [steering](steering-intakt.md#approving) |
| **Approval on the spot** | Intakt approving a change to the rules itself, at once, when no hard limit is touched, a mechanical check proves the change harmless, a separate security review agrees and an attack on the change fails; otherwise you are asked (planned) | [safety](why-the-ai-cant-wreck-your-project.md#when-intakt-asks-you-and-why) |
| **Back-off** | a short wait after a failure before trying again | [engine room](the-engine-room.md#budgets-windows-and-pace) |
| **Blocking reviewer** | a reviewer that must approve before a merge | [how it works](how-intakt-works.md#which-reviewers-look-at-a-change) |
| **Boot check** | starting the changed application to see that it still runs | [journey](the-journey-of-a-task.md#3-checking) |
| **Briefing** | the short summary of project memory every job starts with | [project memory](project-memory.md) |
| **Build slot** | a bench where one builder works on one task | [engine room](the-engine-room.md#lanes-and-slots-the-workbenches) |
| **Builder** | the AI agent that writes the change | [how it works](how-intakt-works.md) |
| **Bundle** | the sealed package of commits a builder hands back | [journey](the-journey-of-a-task.md#2-building) |
| **Catch-up review** | a reviewer's review done later, after a substitute took its place | [engine room](the-engine-room.md#reviewers-substitutes-and-catch-ups) |
| **Checks / gates** | the project's automatic tests and rules (`bin/check`) | [journey](the-journey-of-a-task.md#3-checking) |
| **CI** | GitHub running the checks again on its own machines | [engine room](the-engine-room.md#ci-the-second-opinion) |
| **Core panel** | the reviewers every change gets | [how it works](how-intakt-works.md#which-reviewers-look-at-a-change) |
| **Cycle** | one round of build, check and review | [engine room](the-engine-room.md#cycles-and-generations-second-chances) |
| **Draining** | stopping new work so running jobs can finish before an update | [engine room](the-engine-room.md#draining-and-updates) |
| **Evidence** | what a finished change must show, numbered E1, E2, ... | [writing a good task](writing-a-good-task.md) |
| **Finding** | one problem a reviewer reports, with a severity | [journey](the-journey-of-a-task.md#5-reviewing) |
| **Fixture** | a small sample project a test of Intakt's own checks runs a rule on | [testing a new check](testing-a-new-check.md) |
| **Follow-up** | a small task made from reviewers' nice-to-have notes | [journey](the-journey-of-a-task.md#7-afterwards) |
| **Forge** | where your code lives: GitHub or Gitea | [connections](what-intakt-connects-to.md) |
| **Generation** | a set of cycles with one kind of builder; the next one uses a stronger model | [engine room](the-engine-room.md#cycles-and-generations-second-chances) |
| **Hand-over** | a task going to the maintainer agent for a decision | [engine room](the-engine-room.md#holds-hand-overs-and-caps) |
| **Hands-off** | a label meaning you work on this item yourself and Intakt stays off it until you remove it (planned) | [steering](steering-intakt.md#taking-an-item-over-by-hand-and-giving-it-back) |
| **Head** | the latest commit of a pull request | |
| **Hold** | a pause that lifts by itself when its reason is gone | [how it works](how-intakt-works.md#when-something-gets-stuck) |
| **Issue / task** | one piece of work; on GitHub it is an issue | [writing a good task](writing-a-good-task.md) |
| **Job** | one build, one check or one review, in its own container | [containers](containers-and-services.md) |
| **Journey check** | a run that starts a fresh copy of Intakt and walks one path a person takes, to prove the whole product still works | [checking the whole product](checking-the-whole-product.md) |
| **Judge step** | a quick call that sorts findings into fix now, later, or not needed | [journey](the-journey-of-a-task.md#5-reviewing) |
| **Lane** | a line of work: the build slots and the review lane | [engine room](the-engine-room.md#lanes-and-slots-the-workbenches) |
| **Lock** | a "one at a time" sign on something two jobs must not use together | [engine room](the-engine-room.md#locks-one-at-a-time) |
| **Log** | Intakt's own record of its warnings and errors, one line each, with the secrets Intakt knows about written as `[redacted]` | [why isn't anything happening](why-isnt-anything-happening.md#reading-intakts-log) |
| **Main** | the official version of your project | |
| **Maintainer agent** | the AI agent that settles stuck work, so it rarely reaches you | [how it works](how-intakt-works.md#what-needs-you-and-what-doesnt) |
| **MCP** | a standard way for AI assistants to use tools | [assistant](using-intakt-from-your-assistant.md) |
| **Memory limit** | how much memory a job and the programs it runs may use before they are stopped | [containers](containers-and-services.md#when-a-job-runs-out-of-memory) |
| **Merge** | adding a pull request's change to main | [journey](the-journey-of-a-task.md#6-merging) |
| **Orchestrator** | Intakt itself, the part that runs everything | [how it works](how-intakt-works.md) |
| **Owner** | you: the person who decides what only a person may | [steering](steering-intakt.md) |
| **Owner cap** | at most a few things wait for you at once | [engine room](the-engine-room.md#holds-hand-overs-and-caps) |
| **P1, P2, P3** | finding severities: wrong or unsafe, a real defect, a nice-to-have | [journey](the-journey-of-a-task.md#5-reviewing) |
| **Pace** | the week's use compared with how much of the week has passed | [costs](accounts-and-costs.md) |
| **Parked** | waiting for something outside (CI, you), with the bench given back | [engine room](the-engine-room.md#holds-hand-overs-and-caps) |
| **Plugin** | a swappable connection to a forge, an AI company or a notifier | [writing a plugin](writing-a-plugin.md) |
| **Priority** | `urgent`, `high`, `normal` or `low`, set as a label | [steering](steering-intakt.md) |
| **Probe** | a small test a reviewer writes and runs against the change | [containers](containers-and-services.md#can-reviewers-use-them) |
| **Project memory** | the notebook of what was learned, kept per project | [project memory](project-memory.md) |
| **Protected files** | the project's rules: the checks, the CI setup, the agents' instructions | [safety](why-the-ai-cant-wreck-your-project.md) |
| **Pull request (PR)** | a finished change waiting to be merged | |
| **Queue** | the tasks waiting to be built, and the work waiting to be reviewed | [engine room](the-engine-room.md#queues-who-is-next) |
| **Ratchet list** | the places that break a new rule of the checks when it is switched on, kept in a file that may only get shorter while they are fixed | [testing a new check](testing-a-new-check.md#switching-a-rule-on-before-the-code-is-clean) |
| **Review cap** | how much finished work may wait for review before builders pause | [engine room](the-engine-room.md#queues-who-is-next) |
| **Review credential** | a secret made for one review round; it lets a reviewer read that round and nothing else, and stops working once the round is judged | [safety](why-the-ai-cant-wreck-your-project.md#the-review-server) |
| **Review lane** | the bench where finished work is reviewed and merged | [engine room](the-engine-room.md#lanes-and-slots-the-workbenches) |
| **Review panel** | the reviewers for a change: the core panel plus specialists | [how it works](how-intakt-works.md#which-reviewers-look-at-a-change) |
| **Review server** | the read-only server through which a reviewer reads the head under review | [safety](why-the-ai-cant-wreck-your-project.md#the-review-server) |
| **Reviewer-for-loop mode** | a project setting: Intakt reviews an existing loop's changes in place of one of its reviewers, its verdicts count, and the only thing it writes to the forge is its verdict on each head (a status and a comment) | [costs](accounts-and-costs.md#reviewer-for-the-loop-intakts-review-takes-a-reviewers-place) |
| **Shadow mode** | a project setting: Intakt reviews alongside your existing process, never writes to the forge, and its verdicts never count | [costs](accounts-and-costs.md#shadow-mode-trying-intakt-alongside-your-process) |
| **Share** | a project's part of the week's AI allowance | [several projects](several-projects.md) |
| **Sidecar** | an extra container a job needs, such as a database | [containers](containers-and-services.md) |
| **Skill (reviewer)** | a reviewer role's own checklist and test recipes | [how it works](how-intakt-works.md#which-reviewers-look-at-a-change) |
| **Specialist reviewer** | a reviewer that joins only when the change calls for it | [how it works](how-intakt-works.md#which-reviewers-look-at-a-change) |
| **Stale (note)** | a memory note whose code changed since it was written | [project memory](project-memory.md) |
| **Stand-in** | a cheaper reviewer on an in-between version while the usual reviewer's allowance is tight; the usual reviewer still reviews the final version | [engine room](the-engine-room.md#reviewers-substitutes-and-catch-ups) |
| **Substitute** | a reviewer from another company reviewing in place of one whose every account is over its limit; a catch-up review follows | [engine room](the-engine-room.md#reviewers-substitutes-and-catch-ups) |
| **Subscription reviewer** | a reviewer that runs on a Claude or Codex subscription; for now it cannot read the project, so it can never pass a change on its own | [safety](why-the-ai-cant-wreck-your-project.md#a-reviewer-on-a-claude-or-codex-subscription) |
| **Take-over** | a label that hands Intakt an issue or pull request it did not create (planned) | [steering](steering-intakt.md#taking-an-item-over-by-hand-and-giving-it-back) |
| **Tier** | `strong`, `standard` or `light`: a kind of AI model, mapped to each company's models | [costs](accounts-and-costs.md) |
| **Verdict** | a reviewer's answer: approve, changes with findings, or blocked (held for a person) | [journey](the-journey-of-a-task.md#5-reviewing) |
| **Waiting on you** | the dashboard column with only what needs a person | [dashboard](a-tour-of-the-dashboard.md#waiting-on-you) |
| **Window** | an AI allowance that refills: five-hour and weekly | [costs](accounts-and-costs.md) |
