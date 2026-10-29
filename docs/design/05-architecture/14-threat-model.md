# Threat model: prompt injection and third-party text

Prompt injection cannot be made impossible: a model that reads text an attacker can influence can be steered by it. Intakt does not try to make its models unsteerable. It makes a steered model harmless: every decision that matters is made or checked by plain code, and each model role has only the tools its job needs. This page lists, per role, what untrusted text it reads, what it can do, and which deterministic check stands between its output and any effect. A change that adds a model role, an input to a model or a tool for one updates this page in the same change (see `AGENTS.md`).

## Untrusted text

Untrusted means "may have been written or influenced by someone who is not a person with write access to the project". That covers:
- **Issue and comment text** from accounts without write access, and any part of a task inside an `<!-- external -->` block.
- **Pull request text**, including what a builder wrote.
- **Code under review and in the checkout**, including dependencies in `vendor/` (or `node_modules/` and the like): a comment in a dependency is as able to carry instructions as an issue.
- **CI log excerpts and probe output**, since they print what model-written code and dependencies print.
- **Project memory**, since its entries summarize pull requests and findings that models wrote.
- **Other reviews and findings**, since a steered reviewer's text reaches the judge, the builder and the maintainer agent.

Every model output is untrusted too: it is validated against its schema before use, and anything invalid is an explicit failure, never a silent default.

## Third-party text

- **Only tasks written by people with write access are built.** A task's author must have write access, checked through the forge's permission API when the task becomes eligible and again before each build ([Choosing work](03-choosing-work.md)).
- **Only steering from people with write access counts.** Steering labels, answers and steering comments count only from accounts with write access, checked through the forge's permission API on each event, never from the author association a comment carries ([Labels](04-labels.md), [Questions and steering](05-questions-and-steering.md)). Other comments never reach a prompt.
- **Copied text is marked.** Text a person copies into a task from an untrusted source (an outside report, a forum post, a log) goes inside an `<!-- external -->` ... `<!-- /external -->` block. Intakt fences that block as untrusted although the task itself is trusted; an unclosed block runs to the end of the task.
- **Dependency code is untrusted input** to builders and reviewers, whoever wrote the task.
- **Fencing (arrives with plan T7).** From plan T7 on, every untrusted value that goes into a prompt goes through one fencing helper; until then the one prompt Intakt sends, the Symfony AI vendor's review call, carries the task text unfenced, and T7 routes it through the helper. The helper wraps the value in a block that names its source and uses a boundary the value cannot contain, and the role prompt says that fenced text is data to examine, never instructions to follow. Fencing lowers the odds of steering; the checks below are what make a steered model harmless.

## Roles

### Builder
- **Reads:** the task (with its fenced external blocks), answers and steering comments, the judge's act list, CI log excerpts, the project memory briefing, the checkout including `vendor/`, and a split part's source ref.
- **Can:** run anything inside its job container: edit files, run tests, install packages from the allowed registries, reach its vendor's API, and reach the project's external dependencies the job uses (see [External dependencies](#external-dependencies)). It returns a git bundle, pull request text, and optionally a structured question or upgrade request.
- **Holds:** its vendor's subscription login when the vendor uses one, mounted read-only because the vendor CLI needs it ([Security model](../04-constraints.md#security-model)); the project's credentials only when the owner opts in for build jobs ([New and existing projects](12-new-and-existing-projects.md)).
- **Checks:** the job holds no forge token, signing key or vendor API key, has an egress allowlist (its vendor's API, the package registries and the address of each external dependency the job uses) and a read-only root. What it does to an external dependency happens before any check below; that path has its own [section](#external-dependencies). A credential it holds can leave the job only through that allowlist or in what it hands back, and before Intakt pushes or posts anything a job returned, it redacts the values of every credential mounted or injected into that job and runs the secret scan; a hit fails the hand-back. The hand-back is data: the bundle must build on the expected base and is re-signed by the orchestrator; a check job runs the project's check command in a fresh container; secrets are scanned before every push; protected paths and dependency changes are held by the merge rules and the dependency policy; the change then needs its review panel, green CI on its exact head and the [merge](08-merge.md) steps. A question only parks the task; an upgrade request is one signal to the planner, which picks the model.

### Reviewer (every configured role, specialists included)
- **Reads:** the diff, the task, a read-only checkout at the head including `vendor/`, project memory, and the open findings of earlier rounds.
- **Can:** call read-only repository tools at the exact head (in process or over the per-review MCP server, which serves only that head), and run probes in a no-network sandbox within a per-review budget. Nothing a probe writes reaches the pull request.
- **Holds:** the project's credentials by default, since check and review jobs get them unless the owner withholds them ([New and existing projects](12-new-and-existing-projects.md)), and its vendor's subscription login when the vendor uses one. A steered reviewer could try to copy them into its findings.
- **Checks:** probes run without network, so a credential cannot leave through a probe. Before Intakt posts a review's text (the review body and its findings), it redacts the values of every credential mounted or injected into that job and runs the secret scan over it; a hit fails the review, which counts as `changes`. The verdict is a typed object checked by the Validator; anything else counts as `changes`. It counts only for its identity (head, base, task revision, check commands, review policy) and only when the posted forge review carries its one-time token. Tool arguments that escape the checkout are refused by a listener, not by the prompt. From the second round, a new blocking finding must be a regression or cite a requirement, enforced in code. An approval counts toward the quorum, and a merge still needs green CI and the merge steps.

### Stand-in reviewer
- **Reads and can:** as a reviewer, on an intermediate head.
- **Checks:** its approval is never the last word (the scarcer vendor reviews the same head), it is never used on a head whose last change is a merge of main, and a stand-in for one of the two reviewers sends a protected change to the owner ([Reviews](07-reviews.md)).

### Judge
- **Reads:** the reviewers' findings, the task's acceptance criteria and non-goals.
- **Can:** mark each finding act, defer or reject; no tools.
- **Checks:** its output is a typed list over the finding ids it was given; an unknown id or a missing mark is a failure. It decides only what the next build works on: it never turns a reviewer's `changes` into an approval, because the quorum counts the reviewers' own verdicts on the exact head.

### Triage and sizing
- **Reads:** the task text with its fenced external blocks, and items of the follow-up inbox.
- **Can:** say whether the task is ready, propose a class, estimate a size, and merge, queue or expire follow-ups; no tools.
- **Checks:** it cannot make a task eligible (eligibility is the write-access check and a steering label from a person). A proposed class fills the row only when no label sets one. The size sets a start model and an allowance, both bounded by the owner's routing, the sensitive-path rule (sensitive paths start on the strong tier whatever the size) and the budget caps. A parked task costs one comment.

### Escalation
- **Reads and can:** a stronger model rebuilding a recurring finding is a builder; a job's upgrade request is model output.
- **Checks:** a job never picks its own model; the planner escalates on structured signals (the same check failing twice, a P1, a stall, a request with its reason) within the owner's routing and budget caps ([Budget and model planner](06-budget-and-model-planner.md)).

### Maintainer agent
- **Reads:** blocked verdicts, findings, questions, recurring failures, task and pull request text, and the design documents.
- **Can:** comment, answer questions, write decisions into task descriptions, label, split tasks, resolve conflicts on a branch and write the summaries of approval requests ([Merge](08-merge.md)), through a fixed tool list with a step limit ([The maintainer agent](09-maintainer-agent.md)).
- **Checks:** it runs as its own narrow forge identity in a job container, and the forge, not its prompt, denies it merging, pushing main, approving, changing protections and editing config. A decision it writes changes the task's revision, so earlier verdicts stop counting and the next build is checked and reviewed like any other. It cannot give an owner approval, in a comment or in a summary: its forge identity is never the owner's account, so a review or `approve <sha>` it posts is not the owner's ([Merge](08-merge.md)), and a post guard blocks it as the second barrier. Ruling a hit of the secret check a false positive never releases a push (see below). Every action is in the ledger and on the dashboard.

### Agents over MCP
- **Outside agents** (an owner's agent CLI) reach Intakt's MCP server. They send tool arguments and read verdicts and act lists, which carry text derived from code under review. Starting a review needs the configured token; whether a tool may write is decided by a Symfony Security voter on Intakt's own config, never by the model's arguments; read tools are marked read-only.
- **Reviewers** reach their repository tools as MCP clients of a per-review server that serves only the head under review, read-only.

## Where model output authorizes something

- **Approval by class** ([Merge](08-merge.md)): reviewers' classifications let the orchestrator approve a protected change. Safeguards: at least two reviewers from different vendors, each approving and independently classifying it as tightening or neutral; a missing or invalid class counts as loosening; no stand-in; green CI; a deterministic proof that existing gate code only grows; the protected-path list, the CI definition and the approval rules are always the owner's and live in the owner's config, not the repository; every such approval is listed on the dashboard for a week (information, not a notice).
- **The maintainer agent's decisions** ([The maintainer agent](09-maintainer-agent.md)): answers, decisions in task descriptions, labels, splits and the summaries of approval requests (a summary never counts as an approval). Safeguards: its forge identity's permissions, the task revision in every verdict's identity, the post guard on owner approvals, escalation to the owner for preference, money, tax, pricing and legal choices, and the ledger.
- **A false positive of the secret check** ([The maintainer agent](09-maintainer-agent.md)): the maintainer agent may rule a hit a false positive. Safeguards: the ruling only becomes a decision in the task description for the next build (for example, replace the fixture value that looks like a key); it never skips or relaxes the scan. The scan is fail-closed before every push, its rules and allowlist are protected paths and the owner's, and inline allow directives are refused, so the next head must pass the unchanged scan.
- **Reviewer approvals** count toward the quorum. Safeguards: the quorum policy (by default two reviewers from different vendors), verdict identity, green CI and the merge steps.

## External dependencies

A project can connect its jobs to an external dependency: an instance that is already running, such as a staging backend or a shared dev server ([New and existing projects](12-new-and-existing-projects.md#dependent-services-and-projects)). Intakt adds its address to the job's egress allowlist and injects its credentials into the job types the owner allows: check and review jobs by default, build jobs only on the owner's opt-in. This is the one path where model-written code acts on something outside its container before any review:
- A **build job** that holds the credentials runs code a possibly steered builder just wrote.
- A **check job** runs the project's check command on the head, that is on model-written code, with the credentials, before the review panel reads it. A reviewer's own probes run without network and never reach the dependency.

No deterministic check stands between that code and the shared instance: the hand-back scan, the review panel and the merge rules all come later. A steered job can change or delete data within what the credentials allow, and send data to that host. The safeguards are limits, not checks:
- **Bundled is the default** and runs a fresh sidecar per job on a private network; a stub serves third-party APIs. External is an explicit owner choice per dependency.
- **Isolation is the owner's**, and the wizard says so: test-scoped, low-privilege credentials, the job's own tenant, namespace or prefix (Intakt passes `INTAKT_JOB_ID`), or read-only access. Never connect a production instance.
- **Build jobs** get the credentials only on the owner's opt-in.
- **Credential values** a job held are redacted and scanned before anything it returned is pushed or posted, as for every credential ([Builder](#builder)).

## Residual risk

A steered reviewer can approve a weak change that passes CI. Outside protected paths, that is what the panel's second vendor, the gates and green CI on the exact head are for; a project that runs a panel of one accepts this risk for unprotected changes.

A steered job can try to carry a credential it holds out in a form the redaction and the scan do not recognize (split or encoded), or to an allowlisted host that accepts uploads. That is why the forge token, the signing key and vendor API keys never enter a job, project credentials should be test-scoped and low-privilege, and a subscription login is limited to its vendor account, which the owner can revoke.

A steered build or check job can harm an external dependency within its credentials before anything is reviewed ([External dependencies](#external-dependencies)). The owner accepts that risk by choosing the external mode and bounds it by the scope of the credentials and the instance.
