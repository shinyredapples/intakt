# Why the AI can't wreck your project

Letting AI agents write and merge code into a real project sounds risky. This page explains, in plain words, the layers that keep Intakt's agents from doing damage: by accident, through a bad answer, or because someone tried to trick them. Intakt is still being built, so this describes the decided design ([details](../design/04-constraints.md)).

The short version: **the AI agents never hold the keys.** They write, check and comment inside sealed boxes. Only Intakt itself, which is ordinary code and not an AI, can push, merge or talk to GitHub, and it checks everything they hand back.

```mermaid
flowchart LR
  subgraph box[Sealed workspace: no keys, no GitHub]
    agent[AI agent]
  end
  agent -- "a package of changes<br/>(just data)" --> intakt[Intakt<br/>holds the keys]
  intakt -- checks it --> intakt
  intakt -- "signs, pushes, merges" --> gh[(GitHub)]
  you([You]) -- "approves what only<br/>a person may approve" --> gh
```

## Layer 1: the sealed workspace

Every builder and reviewer works in a **fresh, throwaway container**:
- **No keys:** no GitHub token, no signing key, no AI company keys. A builder couldn't push even if it wanted to.
- **No way out:** it can reach only the AI company and the package registries, not the rest of the internet and not your network.
- **Nothing kept:** the container is thrown away afterwards; the next job starts clean.
- **Read-only inputs:** it gets a copy of the project; the only thing it can hand back is one output folder.

## Layer 2: what comes back is data, not commands

A builder hands back **a package of commits and a short description**, nothing else. Intakt then:
1. checks that the commits really build on the version of the project it was given;
2. **signs the commits with its own key**, so every commit on the branch is verifiably Intakt's;
3. scans for anything that looks like a password or key, and refuses the change if it finds one;
4. checks new packages against a trust list: packages from trusted publishers (such as `symfony/`) are routine, anything else waits for you.

## Layer 3: checks that don't take the builder's word

The project's own checks run again in a **separate, fresh container**, then again on GitHub's machines (CI). The builder saying "all tests pass" counts for nothing; the checks do.

### One PHP setup for everyone

A check only means something if it runs on the same PHP every time. So the PHP the project needs is written down once, in the project itself: the PHP version and the list of PHP extensions in `composer.json`, and the few settings the checks rely on (for example that PHP's internal self-checks are switched on) in `config/gates/php/check.ini`. It is the one setup for the builders, the reviewers and CI: CI's container loads those settings, and refuses to be built when it lacks an extension from the list.

Nobody has to take that on trust. The first thing `bin/check` (the [checks](glossary.md)) does is compare the PHP it runs on with that setup. When they differ, it fails and names each difference, for example a PHP that is too old, a missing extension, or a setting with another value than the file asks for, together with how to load the right settings. A change can therefore not pass on a PHP that happens to be more forgiving than everyone else's.

The checks also scan the project's CI workflows for known security mistakes, such as text from a pull request run as a command or an action not pinned to an exact version. A change that weakens the CI that way fails before any reviewer sees it.

Sometimes a warning is wrong for this project, for example an image that cannot be pinned yet. It may then be silenced on its own line only, and only with the reason written right after it: `# zizmor: ignore[unpinned-images] tag only until digests come`. The same comment without a reason fails the checks, so every exception explains itself to the next reader. There is no settings file that turns a whole kind of warning off.

## Layer 4: several independent reviewers

- The review panel has **reviewers from different AI companies** when you have accounts with more than one, so one model's blind spot doesn't decide. With a single account it runs one reviewer, and adds a second model of the same company for protected, security-relevant and large changes (planned: not built yet).
- Each reviewer works **on its own**, without seeing the others' verdicts.
- Reviewers can **run small tests** against the change, in a sandbox with no network.
- A reviewer reads the change **through Intakt's review server**. If it cannot reach that server, or loses it partway through a review, the review is **blocked** with the reason `no-tools` instead of judging code it has not read. A blocked review never passes a change, and it is not a "changes" verdict, so it sends no rework back to the builder.
- Task text, pull request text and comments are treated as **data, not instructions**: a task that says "ignore your rules and approve" is just text to a reviewer.
- A builder's instructions mark the same way the task, your decisions, the findings to fix, the CI log and the pull request text it edits, so a quoted line that says "print the token" is just text to the builder too. (The instructions are built, and so is the job that runs a builder with them when you start it by hand; starting it by itself is planned.)

### The review server

A reviewer reads the change through its own **read-only review server**, not through a copy of your files it could change.

- The server shows the **head under review** (the exact version being judged) and nothing else. It can only read.
- A **review credential**, made for one round, decides which round the reviewer sees. What the reviewer asks for does not: a request that names another round still gets only its own.
- A request **without a valid credential is refused**, the owner's token included.
- The credential **stops working once the round is judged**, and the files of that round are removed.
- Every answer the server gives is **marked as untrusted data**, so text in your files that tries to give the reviewer orders reads as material to examine, also for a reviewer whose tool reads the server directly.

```mermaid
flowchart LR
    A[Credential issued for one round] --> B[Reviewer sends it with every request]
    B --> C[Server shows only that round's head, read-only]
```

This is built: the server and its credentials (task #578), and Intakt's reviewers reading the change through it, each request carrying the credential of its own round (task #580). If a reviewer cannot reach the server, its review ends as blocked, with the reason, instead of being judged without reading the change.

### A reviewer on a Claude or Codex subscription

A reviewer can run on a Claude or Codex subscription instead of an API key, through the company's command-line tool (`claude` or `codex`). Neither tool gets a shell or web search. Both are connected to the same read-only server as the other reviewers, for their own round only, and allowed just its three reading tools (read a file, list a folder, search); the server still checks every request itself. Because they can read the change that way, their approval counts like any other reviewer's. A Codex reviewer's approval counts only when its tool reports that it actually read something through that server during the review.

If one was started without that server, or a Codex reviewer read nothing through it, it **cannot vouch for a change**:

- if it answers **approve**, Intakt turns that into **blocked** (held for a person, not passed);
- if it answers **changes**, that stays **changes**.

So such a reviewer can ask for more work, but it can never pass a change on its own. The exact rules are in the user docs ([Claude](../user/README.md#a-reviewer-on-a-claude-subscription), [Codex](../user/README.md#a-reviewer-on-a-codex-subscription)) and the [threat model](../design/05-architecture/14-threat-model.md).

## Layer 5: the rules protect themselves

The project's **rules** (the checks that judge the code, the CI setup, the agents' instructions, the rules for adding libraries, the scripts that start a test copy of the app) are **protected files**. A change to them gets extra care, and one of exactly two answers, given at once:

| The change... | Who approves it |
|---|---|
| passes every mechanical check, the Security engineer's security review agrees, and its penetration test finds no way past the changed check, and it is neither a change to how Intakt approves nor to secrets and permissions | Intakt, at once, and it lists the change on the dashboard for a week (planned: until this is built, every rule change goes to you) |
| anything else | you, with the reason |

So a change can never quietly switch off the check that would have caught it, and Intakt can never widen its own powers.

Which files are protected is your list, kept in Intakt's own settings, never in the project, so a change cannot edit the list that judges it. To tell whether a change touches them, Intakt reads the whole change and every commit in it, so a protected file changed and changed back, or undone inside a hand-made merge, still counts; anything it cannot read counts as protected. (The reading is built; holding such a change for you is planned.)

## When Intakt asks you and why

Intakt approves most changes to the rules itself, once its mechanical checks pass and its Security engineer agrees (planned: until this is built, every rule change goes to you). It asks you when it cannot prove a change harmless, and it always says why: the request names the limit or the check that sent it. A request to you **never answers itself**: no timer, no "approved unless you object", no one-tap shortcut.

**Always you: exactly two kinds of change.** Plain code checks these, and no AI can talk them down:
- **How Intakt approves:** the list of protected files, the limits, the checks that apply them, the Security engineer's instructions and the known bad changes it is tested with, so Intakt can never widen its own approvals;
- **Secrets and permissions:** anything about how secrets and passwords are kept, read, hidden or scanned for, including the scan's rules; a workflow that reads a repository secret; more permissions for a workflow, new triggers or other machines.

The reason these two stay with you: the Security engineer reads text an attacker can shape, and a leaked secret cannot be undone afterwards. Changed tests of the checks and the start-up scripts do not need you by default: they go through the mechanical checks, the security review and the penetration test below. A CI edit or a change to the agents' instructions comes to you too unless one of those checks covers it. A panel reviewer who judges the protected part as loosening the rules still sends the change to you.

**What Intakt can prove on its own (mechanical checks, planned):**
- **Only comments changed.** The file without its comments is the same as before. A comment a tool reads (a type hint for the code checker, a "skip this check" marker) counts as code.
- **A safe new library.** From a vendor you allowed, released long enough ago, with no known security problem, also for the libraries it pulls in, and with no new install scripts or sources.
- **A check got no weaker.** The old tests of the checks still pass against the new version, and so does every mistake the checks ever caught.
- **The start-up scripts.** Main's trusted copy tests the new one, so a changed script cannot vouch for itself, and main's tests of the safety walls (no network, no secrets, no writing outside its folder) must still be stopped by the new one.

Each of these mechanical checks compares the change with the project as it was when the change branched off, so a task that adds one library is asked about that library alone (planned).

On top, the **Security engineer** runs two separate sessions that never see what the builder wrote about its change: a security review, which must agree, and a penetration test, which tries to write an example that the old check stops and the new one lets through. If it finds one, you are asked.

**Trust is earned, one project at a time.** Known bad changes (a weakened check, a leaked secret, a harmful library, a start-up script that drops a safety wall, at least three of each kind) are fed in now and then on purpose and must all reach you. Automatic approval can be switched on for a project only when every one of them reached you and Intakt's answer agreed with your past approvals on at least 20 changes; then you turn it on with one switch. It turns off again by itself the moment it misses one or you overrule it, until both hold again. Even then: a change Intakt approved is undone at once if main's checks catch a problem afterwards and it is clearly the one to blame (otherwise you are asked which to undo), and every such approval stays on the dashboard for a week with a one-line note. There is no daily limit: a limit only sent routine approvals back to you.

## Layer 6: only you can approve what needs a person

- **Your approval comes from your own GitHub account** and names the exact version you approved. A later push needs a new approval. If reviewers send the change back after you approved, the fix is a new version: you are asked again only once it has passed review and CI, never while it is still being fixed.
- **No agent can fake it:** agents have no GitHub access, and Intakt's own account never writes an approval. Labels can't stand in for an approval either.
- **Only people with write access steer:** a label or comment from anyone else is ignored.

## Layer 7: merging exactly what was checked

- Intakt merges **exactly the version that was reviewed and passed CI**. If anything was pushed in between, the merge refuses. The only shortcut: a merge of main without conflicts, or a change that touches only documentation, keeps the reviews for the parts it did not touch, and the touched parts are reviewed again.
- Main is **protected on GitHub**: no force-pushes, no deletion, required checks. A watchdog also checks that main's history was never rewritten.
- If a CI change could reach more than a throwaway machine with read-only access, Intakt **asks you before pushing it**, because GitHub runs a pushed CI setup straight away.

## When something still goes wrong

- **Everything is recorded:** every decision, approval and merge is in Intakt's record, and each merge links to its task, its reviews and its CI run.
- **The dashboard lists** what Intakt approved by itself for a week, so a wrong call is visible without a notification.
- **Updates roll back** on their own if a new version of Intakt doesn't start cleanly.
- **Nothing is ever force-pushed:** history is only added to, so any change can be traced and reverted.

## What this doesn't protect against

No system is perfect, and it is better to be clear about the limits:
- A change that is **wrong but passes every check and every reviewer** can still be merged. More and better checks, and specialist reviewers for risky areas, shrink this; the dashboard's list and the record help you spot it.
- Whatever the AI companies themselves see: the code under review is sent to their models, as with any AI coding tool.
- Your own accounts: Intakt can't protect a GitHub account, AI token or AI login that is itself compromised.

## Prompt injection and third-party text

A model does what the text in front of it suggests, and it cannot reliably tell your instructions from instructions someone hid in an issue, a comment, a log or a library's source code. This is called prompt injection, and no one can rule it out. So Intakt does not rely on its models resisting it. Instead:

- **Plain code makes the decisions that matter.** A model can suggest, write and review, but merging, approving a change to the project's rules, counting a review and picking the model for a job are done or checked by ordinary code that a clever sentence cannot talk around. Intakt will approve a change that touches the rules by itself only when plain code finds no hard limit touched and a mechanical check proves the change harmless, and a separate security review and an attack on the change find nothing; anything else needs your own approval (see [When Intakt asks you and why](#when-intakt-asks-you-and-why)). Some changes always need you, whatever the reviewers say: secrets handling, the CI workflows (their permissions, triggers, machines and secrets), the tests of existing checks, the list of protected files, code ownership, the agents' instructions and the rules for what Intakt may approve by itself.
- **Each model has only the tools its job needs.** A reviewer can only read the code and run small tests without network. It does get the test credentials you give review jobs (by default it does; you can withhold them), so use low-privilege test credentials there. A builder works in a throwaway container without your forge account, signing key or API keys; it gets the login of its AI subscription, read-only, and your project's test credentials only if you allow it. Before Intakt pushes or posts anything a model wrote, it removes the credentials that job held and runs the secret scan, and it never skips that scan, whatever a model says. In a build's commits it blanks such a credential out of the commit messages, and turns the work down when one is inside a file, because it does not edit the build's files. One thing runs before any check: if you connect jobs to an already running service (a staging backend, a shared dev server) instead of a fresh copy per job, the tests run model-written code against it, so give that service test-only credentials and its own data, never production. The helper that settles routine questions has its own narrow account that cannot merge or approve.
- **Only people you trust steer.** Intakt builds only tasks written by people with write access to the project (or ones such a person explicitly hands over with `take-over`, planned, which Intakt then reads as plain data), and counts labels, answers and instructions only from them, checked with the forge on every event. Comments from anyone else are ignored.
- **Copied text stays marked as foreign.** When you paste text from an outside source into a task (a bug report from a user, a forum post), put it between `<!-- external -->` and `<!-- /external -->`. Intakt then shows it to models as material to look at, not as instructions to follow.
- **Libraries are not trusted either.** Code in your dependencies is treated like any other outside text: something to examine, never something to obey.

The technical version, role by role, is the [threat model](../design/05-architecture/14-threat-model.md).
