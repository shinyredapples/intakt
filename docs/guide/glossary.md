# Glossary

The words used across Intakt's guides and dashboard, in plain terms. Each entry links to the guide that explains it best.

| Word | Meaning | More |
|---|---|---|
| **Acceptance** | the checkable results that say a task is done | [writing a good task](writing-a-good-task.md) |
| **Advisory reviewer** | a specialist whose notes become follow-ups but never block | [how it works](how-intakt-works.md#which-reviewers-look-at-a-change) |
| **AI engineer** (planned, optional) | the AI agent that looks at where model tokens go and how the agents work, and tries better prompts, skills or model choices as measured experiments | [how it works](how-intakt-works.md) |
| **Approval** | your OK, from your own account, naming one exact version | [steering](steering-intakt.md#approving) |
| **Approval on the spot** | Intakt approving a change to the rules itself, at once, when no hard limit is touched, a mechanical check proves the change harmless, the Security engineer's security review agrees and its penetration test fails to get around the change; otherwise you are asked (planned) | [safety](why-the-ai-cant-wreck-your-project.md#when-intakt-asks-you-and-why) |
| **Back-off** | a short wait after a failure before trying again | [engine room](the-engine-room.md#budgets-windows-and-pace) |
| **Blocking reviewer** | a reviewer that must approve before a merge | [how it works](how-intakt-works.md#which-reviewers-look-at-a-change) |
| **Boot check** | starting the changed application to see that it still runs; Intakt does this only for a pull request by someone it trusts (a person with write access, Intakt's own GitHub App or the maintainer), and reviews anyone else's pull request without starting it | [journey](the-journey-of-a-task.md#3-checking) |
| **Briefing** | the short summary of project memory every job starts with | [project memory](project-memory.md) |
| **Build slot** | a bench where one Developer works on one task | [engine room](the-engine-room.md#lanes-and-slots-the-workbenches) |
| **Bundle** | the sealed package of commits a Developer hands back | [journey](the-journey-of-a-task.md#2-building) |
| **Catch-up review** | a reviewer's review done later, after a substitute took its place | [engine room](the-engine-room.md#reviewers-substitutes-and-catch-ups) |
| **Checks / gates** | machinery: the project's automatic tests and rules (`bin/check` in Intakt itself) | [journey](the-journey-of-a-task.md#3-checking) |
| **CI** | the project's full check run again on the pushed version, on a machine the Developer does not control: today GitHub's machines; planned: a fresh container Intakt starts by default, or GitHub's machines where the project sets it | [engine room](the-engine-room.md#ci-the-second-opinion) |
| **Code reviewers** | the AI agents that review a change, each with its own focus: the core panel plus specialists, with a judge step at the end (formerly the review panel) | [how it works](how-intakt-works.md#which-reviewers-look-at-a-change) |
| **Cooldown (for a release)** | the 3 days Intakt waits before it takes a new release of a PHP or dashboard JavaScript package | [Symfony in Intakt](symfony-in-intakt.md#waiting-before-taking-a-new-release) |
| **Core panel** | the Code reviewers every change gets | [how it works](how-intakt-works.md#which-reviewers-look-at-a-change) |
| **Cycle** | one round of build, check and review | [engine room](the-engine-room.md#cycles-and-generations-second-chances) |
| **Dashboard** | machinery: the page where you see what Intakt does and act on it | [dashboard](a-tour-of-the-dashboard.md) |
| **Definition of Done** | what the Developer checks before it hands a change back | [writing a good task](writing-a-good-task.md#definition-of-done) |
| **Definition of Ready** | what a task needs before it is built; the Tech lead checks it | [writing a good task](writing-a-good-task.md#definition-of-ready) |
| **Designer** (planned, optional) | the AI agent that plans and judges your project's screens, only for tasks that touch them: automatic checks, scores with a reason, and your taste learned by choosing between two versions | [how it works](how-intakt-works.md#the-idea-in-one-sentence) |
| **Developer** | the AI agent that writes the change (formerly the builder) | [how it works](how-intakt-works.md) |
| **Draining** | stopping new work so running jobs can finish before an update; planned: a normal update drains nothing (a running job finishes on the version it started on), and only a new version that says it needs one drains: every build slot before the restart when it changes how jobs talk to Intakt or are kept apart (it then runs its own check and first build after the restart, and goes back to the old version if they fail), or all work when it changes stored data the old version could not read | [engine room](the-engine-room.md#draining-and-updates) |
| **Evidence** | what a finished change must show, numbered E1, E2, ... | [writing a good task](writing-a-good-task.md) |
| **Finding** | one problem a reviewer reports, with a severity | [journey](the-journey-of-a-task.md#5-reviewing) |
| **Fixture** | a small sample project a test of Intakt's own checks runs a rule on | [testing a new check](testing-a-new-check.md) |
| **Follow-up** | a small task made from reviewers' nice-to-have notes | [journey](the-journey-of-a-task.md#7-afterwards) |
| **Forge** | where your code lives: GitHub or Gitea | [connections](what-intakt-connects-to.md) |
| **Generation** | a set of cycles with one kind of Developer; the next one uses a stronger model | [engine room](the-engine-room.md#cycles-and-generations-second-chances) |
| **Hand-over** | a task going to the Maintainer for a decision | [engine room](the-engine-room.md#holds-hand-overs-and-caps) |
| **Hands-off** | a label meaning you work on this item yourself and Intakt stays off it until you remove it (planned) | [steering](steering-intakt.md#taking-an-item-over-by-hand-and-giving-it-back) |
| **Head** | the latest commit of a pull request | |
| **Hold** | a pause that lifts by itself when its reason is gone | [how it works](how-intakt-works.md#when-something-gets-stuck) |
| **Import map** | the list that tells the browser where each JavaScript module of the dashboard lives (`importmap.php`) | [Symfony in Intakt](symfony-in-intakt.md#waiting-before-taking-a-new-release) |
| **In-flight limit** | how much finished but not yet merged work may exist, whatever it waits for, before Developers pause; twice the review cap by default | [engine room](the-engine-room.md#queues-who-is-next) |
| **Integrity hash** | a fingerprint (sha384) of a module's exact bytes in the import map; the browser refuses a module that does not match it | [Symfony in Intakt](symfony-in-intakt.md#waiting-before-taking-a-new-release) |
| **Issue / task** | one piece of work; on GitHub it is an issue | [writing a good task](writing-a-good-task.md) |
| **Job** | one build, one check or one review, in its own container | [containers](containers-and-services.md) |
| **Journey check** | a run that walks one path a person takes, to prove the whole product still works: `bin/check --journeys` starts a fresh copy of Intakt for each journey, and `bin/journey-check` walks its journeys in a real browser against an Intakt that is already running (it has none of its own yet) | [checking the whole product](checking-the-whole-product.md) |
| **Judge step** | the Code reviewers' last step, a quick call that sorts the findings: a serious one (P1 or P2) is always fixed now, with the judge's doubt written beside it, and a small one (P3) is fixed now, later, or not at all | [journey](the-journey-of-a-task.md#5-reviewing) |
| **Judge's note** | the judge's one-sentence reason, kept beside a serious finding (P1 or P2) it doubts, on the list of things to fix; the finding stays on the list either way | [assistant](using-intakt-from-your-assistant.md#the-judges-note-on-the-act-list) |
| **Lane** | a line of work: the build slots and the review lane | [engine room](the-engine-room.md#lanes-and-slots-the-workbenches) |
| **Ledger** | Intakt's permanent record of what it did, one line per event, which is only ever added to (removing the demo project also removes the events it replayed) | [running one task](running-one-task.md#finding-the-steps-in-the-ledger) |
| **Live (version)** | planned: a new version of Intakt during an update, once it holds the one right to write the records (the owner generation) and its start checks passed; from then on it hands out the work | [engine room](the-engine-room.md#draining-and-updates) |
| **Lock** | a "one at a time" sign on something two jobs must not use together | [engine room](the-engine-room.md#locks-one-at-a-time) |
| **Log** | Intakt's own record of its warnings and errors (or more, with a lower log level), one line each, with the secrets Intakt knows about written as `[redacted]` | [why isn't anything happening](why-isnt-anything-happening.md#reading-intakts-log) |
| **Main** | the official version of your project | |
| **Maintainer** | the AI agent that settles stuck work, so it rarely reaches you, and looks at speed and quality every week (planned) (formerly the maintainer agent) | [how it works](how-intakt-works.md#what-needs-you-and-what-doesnt) |
| **MCP** | a standard way for AI assistants to use tools | [assistant](using-intakt-from-your-assistant.md) |
| **Memory limit** | how much memory a job and the programs it runs may use before they are stopped | [containers](containers-and-services.md#when-a-job-runs-out-of-memory) |
| **Merge** | adding a pull request's change to main | [journey](the-journey-of-a-task.md#6-merging) |
| **On trial** | planned: a live new version until its own check after the update and the first job of each kind it started look right; a failure until then takes Intakt back to the old version | [engine room](the-engine-room.md#draining-and-updates) |
| **Orchestrator** | machinery: Intakt itself, the part that runs everything | [how it works](how-intakt-works.md) |
| **Owner cap** | at most a few things wait for you at once | [engine room](the-engine-room.md#holds-hand-overs-and-caps) |
| **P1, P2, P3** | finding severities: wrong or unsafe, a real defect, a nice-to-have | [journey](the-journey-of-a-task.md#5-reviewing) |
| **Pace** | the week's use compared with how much of the week has passed | [costs](accounts-and-costs.md) |
| **Parked** | waiting for something outside (CI, you), with the bench given back | [engine room](the-engine-room.md#holds-hand-overs-and-caps) |
| **Penetration tester** (planned) | the Security engineer trying to get around a change to the project's rules before it is approved (formerly the attacker) | [safety](why-the-ai-cant-wreck-your-project.md#when-intakt-asks-you-and-why) |
| **Plugin** | a swappable connection to a forge, an AI company or a notifier | [writing a plugin](writing-a-plugin.md) |
| **Priority** | `urgent`, `high`, `normal` or `low`, set as a label | [steering](steering-intakt.md) |
| **Probe** | a small test a reviewer writes and runs against the change (planned, not built yet) | [containers](containers-and-services.md#can-reviewers-use-them) |
| **Product owner** | you, the person, not a model: you decide what gets built and what only a person may (also just the owner) | [steering](steering-intakt.md) |
| **Project memory** | the notebook of what was learned, kept per project | [project memory](project-memory.md) |
| **Protected files** | the project's rules: the checks, the CI setup, the agents' instructions | [safety](why-the-ai-cant-wreck-your-project.md) |
| **Pull request (PR)** | a finished change waiting to be merged | |
| **Queue** | the tasks waiting to be built, and the work waiting to be reviewed | [engine room](the-engine-room.md#queues-who-is-next) |
| **Ratchet list** | the places that break a new rule of the checks when it is switched on, kept in a file that may only get shorter while they are fixed | [testing a new check](testing-a-new-check.md#switching-a-rule-on-before-the-code-is-clean) |
| **Review bench** | eight small changes with known mistakes (three have none) that measure how many the reviewers catch and how often they flag one by error; it calls your paid models | [review bench](review-bench.md) |
| **Review cap** | how much finished work may wait for review before Developers pause | [engine room](the-engine-room.md#queues-who-is-next) |
| **Review credential** | a secret made for one review round; it lets a reviewer read that round and nothing else, and stops working once the round is judged | [safety](why-the-ai-cant-wreck-your-project.md#the-review-server) |
| **Review lane** | the bench where finished work is reviewed, then handed to the landing step | [engine room](the-engine-room.md#lanes-and-slots-the-workbenches) |
| **Review server** | the read-only server through which a reviewer reads the head under review | [safety](why-the-ai-cant-wreck-your-project.md#the-review-server) |
| **Reviewer-for-loop mode** | a project setting: Intakt reviews the changes of a build loop, such as the build loop that developed Intakt, in place of one of its reviewers, its verdicts count, and the only things it writes to the forge are its verdict on each head (a status and a comment), the `status:` label of the pull request and, when someone changed that label, one comment saying it was put back | [costs](accounts-and-costs.md#reviewer-for-the-loop-intakts-review-takes-a-reviewers-place) |
| **Router provider** | the company behind a router (such as Hugging Face) that actually runs a model, named after a colon in the model's name (`:deepinfra`); a rate limit from it pauses it on that account only | [engine room](the-engine-room.md#router-providers-and-their-pause) |
| **Scheduler** | machinery: the part that runs timed jobs, today the regular look for pull requests to review | [how it works](how-intakt-works.md) |
| **Security engineer** (planned, optional) | the AI agent that reviews security in the code, the pages and the containers, and acts as the penetration tester (formerly the gatekeeper's security reviewer and attacker) | [safety](why-the-ai-cant-wreck-your-project.md#checking-the-security-of-what-is-built) |
| **Shadow mode** | a former project setting, no longer available: a configuration that still sets it stops Intakt from starting | [costs](accounts-and-costs.md#the-former-shadow-mode) |
| **Share** | a project's part of the week's AI allowance | [several projects](several-projects.md) |
| **Sidecar** | an extra container a job needs, such as a database | [containers](containers-and-services.md) |
| **Skill (reviewer)** | a reviewer role's own checklist and test recipes | [how it works](how-intakt-works.md#which-reviewers-look-at-a-change) |
| **Specialist reviewer** | a reviewer that joins only when the change calls for it | [how it works](how-intakt-works.md#which-reviewers-look-at-a-change) |
| **Stale (note)** | a memory note whose code changed since it was written | [project memory](project-memory.md) |
| **Stand-in** | a cheaper reviewer on an in-between version while the usual reviewer's allowance is tight; the usual reviewer still reviews the final version | [engine room](the-engine-room.md#reviewers-substitutes-and-catch-ups) |
| **Subscription reviewer** | a reviewer that runs on a Claude or Codex subscription; it reads its round's change through the read-only server, and its approval counts only when its call received that server and the reviewer read through it (a Claude or a Codex one alike; a Claude one must also report that server connected); otherwise, an approval becomes blocked | [safety](why-the-ai-cant-wreck-your-project.md#a-reviewer-on-a-claude-or-codex-subscription) |
| **Substitute** | a reviewer from another company reviewing in place of one no account can serve right now (each account either over its own limit or with the router provider its model names paused on it); a catch-up review follows | [engine room](the-engine-room.md#reviewers-substitutes-and-catch-ups) |
| **Take-over** | a label that hands Intakt an issue or pull request it did not create (planned) | [steering](steering-intakt.md#taking-an-item-over-by-hand-and-giving-it-back) |
| **Tech lead** | the AI agent that checks a task against the Definition of Ready, and sizes, splits and plans it (formerly the sizer, triage and the planner) | [writing a good task](writing-a-good-task.md#definition-of-ready) |
| **Technical writer** (planned) | the AI agent that checks that the pages people read are easy to follow, only for changes that touch them; it never checks them against the code | [how it works](how-intakt-works.md#the-idea-in-one-sentence) |
| **Test engineer** (planned, optional) | an AI agent that keeps the tests fast without losing what they prove | [how it works](how-intakt-works.md) |
| **Tier** | `strong`, `standard` or `light`: a kind of AI model, mapped to each company's models | [costs](accounts-and-costs.md) |
| **Verdict** | a reviewer's answer: approve, or changes with findings; blocked (held for a person) only when Intakt holds the review back. A reviewer that answers blocked with findings is recorded as changes. A changes answer votes for changes only when one of its findings is on the list to fix. When its only findings are small points the judge set aside, it counts as approving. Otherwise (for example when every finding quoted code the change does not hold, so all were dropped) it counts as blocked, so it can still hold the round back. If it names a finding like one of Intakt's own failures, it counts as an answer that could not be read | [journey](the-journey-of-a-task.md#5-reviewing) |
| **Waiting on you** | the dashboard page (`/waiting`) that lists the outages only you can act on; the board column of the same name already exists and is empty; its cards and phone notifications are planned | [dashboard](a-tour-of-the-dashboard.md#waiting-on-you) |
| **Watchdog** | machinery: the part that watches main and raises the alarm if its history was rewritten (planned) | [safety](why-the-ai-cant-wreck-your-project.md) |
| **Window** | an AI allowance that refills: five-hour and weekly | [costs](accounts-and-costs.md) |
