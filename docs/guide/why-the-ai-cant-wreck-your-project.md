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

A builder hands back **a package of commits and a short description**, or **a question** when it needs a decision from you, nothing else. A question is plain text that Intakt checks against a fixed shape (a question, a few choices, a recommendation among them) and refuses otherwise; it changes no code. For commits and a description, Intakt then:
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

The same rule holds for unfinished work. A comment that starts with `TODO`, `FIXME` or `XXX` (in the PHP code, the Twig templates, the JavaScript files and the shell scripts, which are the `.sh` files and the scripts without an extension whose first line says they run in a shell), a test switched off without a reason, or code that only says "not implemented" fails the checks when the change adds it, so nothing half-done slips in unnoticed. If a piece really has to wait, open an issue for it and write the comment as `TODO(#123): drop once the forge sends the total`: the issue number in brackets, a colon, then the reason. A not-implemented line needs such a comment on the same line. The checks then let it through, because the reader learns where the rest of the work is tracked and why it waits. They check only this form, not whether the issue is still open.

## Layer 4: several independent reviewers

- The review panel has **reviewers from different AI companies** when you have accounts with more than one, so one model's blind spot doesn't decide. With a single account it runs one reviewer, and adds a second model of the same company for protected, security-relevant and large changes (planned: not built yet).
- Each reviewer works **on its own**, without seeing the others' verdicts.
- Reviewers read the change; running **small tests** against it, in a sandbox with no network, is planned (not built yet).
- A reviewer reads the change **through Intakt's review server**. If it cannot reach that server, or loses it partway through a review, the review is **blocked** with the reason `no-tools` instead of judging code it has not read. A blocked review never passes a change, and it is not a "changes" verdict, so it sends no rework back to the builder.
- Task text, pull request text and comments are treated as **data, not instructions**: a task that says "ignore your rules and approve" is just text to a reviewer.
- A builder's instructions mark the same way the task, your decisions, the findings to fix, the CI log and the pull request text it edits, so a quoted line that says "print the token" is just text to the builder too. A task, CI log excerpt or pull request text bigger than 100000 bytes is cut to at most that size (a little less so no character is split), and Intakt's own note after the marked text, outside it, tells the builder how much was left out. (The instructions are built, and so is the job that runs a builder with them when you start it by hand; starting it by itself is planned.)

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

A reviewer can run on a Claude or Codex subscription instead of an API key, through the company's command-line tool (`claude` or `codex`). Neither tool gets a shell or web search. Both are connected to the same read-only server as the other reviewers, for their own round only, and allowed just its three reading tools (read a file, list a folder, search); the server still checks every request itself. Because they can read the change that way, their approval can count like any other reviewer's, but only when the tool reports that it actually read something through that server during the review (the Claude tool must also report that it connected to that server).

If one was started without that server, or read nothing through it, it **cannot vouch for a change**:

- if it answers **approve**, Intakt turns that into **blocked** (held for a person, not passed);
- if it answers **changes**, that stays **changes**.

So such a reviewer can ask for more work, but it can never pass a change on its own. The exact rules are in the user docs ([Claude](../reference/user-manual.md#a-reviewer-on-a-claude-subscription), [Codex](../reference/user-manual.md#a-reviewer-on-a-codex-subscription)) and the [threat model](../design/05-architecture/14-threat-model.md).

## Layer 5: the rules protect themselves

The project's **rules** (the checks that judge the code, the CI setup, the agents' instructions, the rules for adding libraries, the scripts that start a test copy of the app) are **protected files**. A change to them gets extra care, and one of exactly two answers, given at once unless the Security engineer cannot run:

| The change... | Who approves it |
|---|---|
| passes every mechanical check, the Security engineer's security review agrees, and its penetration test finds no way past the changed check, and it is neither a change to how Intakt approves nor to secrets and permissions | Intakt, at once, and it lists the change on the dashboard for a week (planned: until this is built, every rule change goes to you) |
| needs the security review or the penetration test, and that session cannot run (for example its account hit its usage limit) | nobody yet: it waits without an answer and carries on by itself when a session runs again (planned, see [below](#when-intakt-asks-you-and-why)) |
| anything else | you, with the reason |

So a change can never quietly switch off the check that would have caught it, and Intakt can never widen its own powers.

Which files are protected is your list, kept in Intakt's own settings, never in the project, so a change cannot edit the list that judges it. To tell whether a change touches them, Intakt reads the whole change and every commit in it, so a protected file changed and changed back, or undone inside a hand-made merge, still counts; anything it cannot read counts as protected. (The reading is built; holding such a change for you is planned.)

## When Intakt asks you and why

Intakt approves most changes to the rules itself, once its mechanical checks pass and its Security engineer agrees (planned: until this is built, every rule change goes to you). It asks you when it cannot prove a change harmless, and it always says why: the request names the limit or the check that sent it. A request to you **never answers itself**: no timer, no "approved unless you object", no one-tap shortcut.

**Always you: exactly two kinds of change.** Plain code checks these, and no AI can talk them down:
- **How Intakt approves:** the list of protected files, the limits, the checks that apply them, the Security engineer's instructions and the known bad changes it is tested with, so Intakt can never widen its own approvals;
- **Secrets and permissions:** anything about how secrets and passwords are kept, read, hidden or scanned for, including the scan's rules; a workflow that reads a repository secret; more permissions for a workflow, new triggers or other machines.

What counts as secrets handling is a fixed list of files and code areas in your project's settings in Intakt (the secret scan's rules, the code that keeps, passes on or hides passwords and keys), not every file with "secret" in its name. A test counts for this limit only if the list names it, so changing the sample input of a test that is not on the list does not hit the secrets limit (the change can still come to you for another reason, such as a CI edit no check covers). A test that is on the list is part of the limit like any other file there: changing it asks you. The request and the notice on your phone tell you in plain words which limit it hit and which file changed; it is a question for you, not an alarm. If you approved a change and its fix hits the same limits with the same files, left exactly as you approved them, the fix does not ask you again; if it changes, adds or removes one of them, it does. A CI change held back until you allow the push is the exception: each new version of it is shown to you again before it is pushed. (Planned: until this is built, every rule change goes to you.)

The reason these two stay with you: the Security engineer reads text an attacker can shape, and a leaked secret cannot be undone afterwards. Changed tests of the checks and the start-up scripts do not need you by default: they go through the mechanical checks, the security review and the penetration test below. A CI edit or a change to the agents' instructions comes to you too unless one of those checks covers it. A panel reviewer who judges the protected part, as it stands now, as loosening the rules, or gives it no valid class, still sends the change to you. A reviewer's classification of the protected part, given on an older version of the change, never counts for the current one, even when a clean merge of main lets that reviewer's verdict on the untouched parts carry over.

**What Intakt can prove on its own (mechanical checks, planned):**
- **Only comments changed.** The file without its comments is the same as before. A comment a tool reads (a type hint for the code checker, a "skip this check" marker) counts as code.
- **A safe new library.** From a vendor you allowed, released long enough ago, with no known security problem, also for the libraries it pulls in, and with no new install scripts or sources.
- **A check got no weaker.** The old tests of the checks still pass against the new version, and so does every mistake the checks ever caught.
- **The start-up scripts.** Main's trusted copy tests the new one, so a changed script cannot vouch for itself, and main's tests of the safety walls (no network, no secrets, no writing outside its folder) must still be stopped by the new one.

Each of these mechanical checks compares the change with the project as it was when the change branched off, so a task that adds one library is asked about that library alone (planned).

On top, the **Security engineer** runs two separate sessions that never see what the builder wrote about its change: a security review, which must agree, and a penetration test, which tries to write an example that the old check stops and the new one lets through. If it finds one, you are asked.

**When the Security engineer cannot run (planned).** If either of its two sessions cannot run, for example because its account hit its usage limit or its login expired, the waiting rule changes wait: nothing is approved by waiting, and nothing is sent to you either, since nobody has judged them yet, so they do not fill your queue. Intakt tries again by itself. If the outage lasts longer than a set time (a couple of hours), you get one message for the whole outage, not one per change, saying what you can do: add an account for the Security engineer or free one. When a session runs again, the message clears and the changes carry on: until automatic approval is switched on for the project, each of them then comes to you with Intakt's answer, as every rule change does; after that, one that passes every check is approved without you. A session that did run but gave no usable answer is different: that change comes to you, as before. If no Security engineer is set up at all, every rule change comes to you.

**Trust is earned, one project at a time.** Known bad changes (a weakened check, a leaked secret, a harmful library, a start-up script that drops a safety wall, at least three of each kind) are fed in now and then on purpose and must all reach you. Automatic approval can be switched on for a project only when every one of them reached you and Intakt's answer agreed with your past approvals on at least 20 changes; then you turn it on with one switch. It turns off again by itself the moment it misses one or you overrule it, until both hold again. Even then: a change Intakt approved is undone at once if main's checks catch a problem afterwards and it is clearly the one to blame (otherwise you are asked which to undo), and every such approval stays on the dashboard for a week with a one-line note. There is no daily limit: a limit only sent routine approvals back to you.

## Layer 6: only you can approve what needs a person

- **Your approval comes from your own GitHub account** and names the exact version you approved. A later push needs a new approval. If reviewers send the change back after you approved, the fix is a new version: you are asked again only once it has passed review and CI, never while it is still being fixed. (Planned:) A decision only you can make, such as a new package from an outside company, is the exception: it is asked as soon as it shows up, and your answer holds for later versions that leave that part unchanged, while the merge still waits for passing checks and reviews. A merge you approved because the change hit a limit such as secrets holds the same way: a fix that leaves the same files hitting the same limits, exactly as you approved them, does not ask you again ([above](#when-intakt-asks-you-and-why)).
- **No agent can fake it:** agents have no GitHub access, and Intakt's own account never writes an approval. Labels can't stand in for an approval either.
- **Only people with write access steer:** a label or comment from anyone else is ignored.

## Layer 7: merging exactly what was checked

- Intakt merges **exactly the version that was reviewed and passed CI**. If anything was pushed in between, the merge refuses. The only shortcut: a merge of main without conflicts, or a change that touches only documentation, keeps the reviews for the parts it did not touch, and the touched parts are reviewed again.
- Intakt **recommends protecting main on GitHub** (planned, as a step of setup): no force-pushes, no deletion, required checks. GitHub offers this protection only where your plan does: a public repository can always have it, but a private repository on GitHub's free plan cannot enforce it. Wherever main is not protected that way, the dashboard is to say so, and that the watchdog below is then main's only guard (planned too: the dashboard does not show this yet).
- **A watchdog looks at every new commit on main** (planned) before each step and merge. It does more than notice that main's history was rewritten: for every new commit it judges how that commit got onto main, and a commit passes only when it arrived in a way the watchdog trusts. A merge Intakt made and recorded passes first, before any signature is checked, together with the commits that merge brought to main (but not commits main already had before it). Any other commit passes only when it is signed with a key you registered. A commit without a signature, a commit signed with a key you did not register, and history that was rewritten (a force push) are violations. The watchdog reads main only from the repository itself and only with its whole history: a plain folder inside a checkout (not a checkout of its own) or a shallow copy is refused as unreadable, never judged clean. A violation is to halt the project: Intakt takes no further step and merges nothing, and shows you the commit and the reason. Only you can lift the halt, after checking the commit. So far Intakt has the rules that sort the commits, the part that reads main and checks each signature against your SSH keys, and commands you start by hand: one that runs this check, and one that also halts the project and gives you a card naming the commit and the reason. While a card stands, the decision that says whether a step may start or a change may merge answers "halted", and only you can clear the card, with your owner token. A commit you pushed yourself, signed with your registered key, halts nothing: the halting command tells you about it once, and running it again or from another copy of the same project does not repeat it. One card halts every project for now, because a step does not yet say which project it belongs to. No step or merge asks that decision yet, so today a card does not stop live work by itself. Running the check on its own, and having steps and merges ask that decision, comes later. The design also plans rules based on what GitHub reports (a merged pull request or a push by someone with write access, which shows as "pushed without the gate"); how those and the signature rules work together is still open.
- If a CI change could reach more than a throwaway machine with read-only access, Intakt **asks you before pushing it**, because GitHub runs a pushed CI setup straight away.

## When something still goes wrong

- **Everything is recorded:** every decision, approval and merge is in Intakt's record, and each merge links to its task, its reviews and its CI run.
- **The dashboard lists** what Intakt approved by itself for a week, so a wrong call is visible without a notification.
- **Updates roll back** on their own if a new version of Intakt doesn't start cleanly.
- **Nothing is ever force-pushed:** history is only added to, so any change can be traced and reverted.

## Checking the security of what is built

The layers above keep the AI from harming your project. A separate question is whether the program it builds is safe for the people who use it. Intakt's **Security engineer** looks after that (planned: none of this is built yet, apart from the check for known holes in PHP packages, which runs on Intakt's own project today). Its rule is simple: automatic checks first, and the Security engineer for what a check cannot judge.

The checks, layer by layer:
- **The server side (backend):** text that comes from outside (a form, a web request, an AI's answer) must never reach a database query, a shell command or a file path without being made safe first; a check follows it there. Logins and sessions follow fixed rules, and a page that prints outside text without escaping it is refused. PHP packages with known security holes are refused.
- **The browser side (frontend):** a test checks that every page tells the browser to run only the program's own scripts (a content security policy) and sends the other safety headers. Scripts written straight into a page, and Twig's `|raw`, which prints text without escaping it, are refused. JavaScript packages with known holes are refused too.
- **The machines (infrastructure):** the container recipe (the Dockerfile) is checked for unsafe habits, the finished container and its settings are scanned for known holes, containers must not run as the all-powerful root user and must not change their own files, and the encryption (TLS) and web server settings are checked.
- **The running program:** every night a scan visits the copy of Intakt that the journeys start and looks for what only shows while it runs, such as a page that forgets a safety header.

A check that needs an extra tool is off until you switch it on for your project, so a simple install stays simple; Intakt's own project will have them all on as each check lands.

The Security engineer adds what checks cannot do. When a change touches a sensitive area (code that handles outside input, the pages and scripts, or the container and web server setup), it reviews that change with a checklist for that area. Once a month it looks at how the scan results develop, keeps the list of threats up to date and files what it finds as tasks, which wait until you or someone with write access labels them. It also runs the two security sessions for changes to the rules (see [When Intakt asks you and why](#when-intakt-asks-you-and-why)), but the decision itself stays with plain code and with you.

## What this doesn't protect against

No system is perfect, and it is better to be clear about the limits:
- A change that is **wrong but passes every check and every reviewer** can still be merged. More and better checks, and specialist reviewers for risky areas, shrink this; the dashboard's list and the record help you spot it.
- Whatever the AI companies themselves see: the code under review is sent to their models, as with any AI coding tool.
- Your own accounts: Intakt can't protect a GitHub account, AI token or AI login that is itself compromised.

## Prompt injection and third-party text

A model does what the text in front of it suggests, and it cannot reliably tell your instructions from instructions someone hid in an issue, a comment, a log or a library's source code. This is called prompt injection, and no one can rule it out. So Intakt does not rely on its models resisting it. Instead:

- **Plain code makes the decisions that matter.** A model can suggest, write and review, but merging, approving a change to the project's rules, counting a review and picking the model for a job are done or checked by ordinary code that a clever sentence cannot talk around. Intakt will approve a change that touches the rules by itself only when plain code finds no hard limit touched and a mechanical check proves the change harmless, and a separate security review and an attack on the change find nothing; anything else needs your own approval (see [When Intakt asks you and why](#when-intakt-asks-you-and-why)). Some changes always need you, whatever the reviewers say: secrets handling, the CI workflows (their permissions, triggers, machines and secrets), the tests of existing checks, the list of protected files, code ownership, the agents' instructions and the rules for what Intakt may approve by itself.
- **Each model has only the tools its job needs.** A reviewer can only read the code (running small tests without network is planned). It does get the test credentials you give review jobs (by default it does; you can withhold them), so use low-privilege test credentials there. A builder works in a throwaway container without your forge account, signing key or API keys; it gets the login of its AI subscription, read-only, and your project's test credentials only if you allow it. Before Intakt pushes or posts anything a model wrote, it removes the credentials that job held and runs the secret scan, and it never skips that scan, whatever a model says. In a build's commits it blanks such a credential out of the commit messages, and turns the work down when one is inside a file, because it does not edit the build's files. One thing runs before any check: if you connect jobs to an already running service (a staging backend, a shared dev server) instead of a fresh copy per job, the tests run model-written code against it, so give that service test-only credentials and its own data, never production. The helper that settles routine questions has its own narrow account that cannot merge or approve.
- **(Planned:) Work that fails the secret scan is held, not published.** When a build fails the secret scan for the last time it is allowed to (a first failure still goes back to the builder), the work is not thrown away and not published either: it stays on Intakt's own machine, out of reach of every job, until the maintainer agent or you judge the finding. The agent's ruling alone releases nothing: if the finding was a false alarm, you first approve the change to the scanner's rules or allowlist that stops it from matching, and the work goes on only once it passes a new scan; if it was real, or nobody judged it in time, the work is deleted and the task is built again, told where the problem was but never shown the secret itself.
- **Only people you trust steer.** Intakt builds only tasks written by people with write access to the project (or ones such a person explicitly hands over with `take-over`, planned, which Intakt then reads as plain data), and counts labels, answers and instructions only from them, checked with the forge on every event. Comments from anyone else are ignored.
- **Copied text stays marked as foreign.** When you paste text from an outside source into a task (a bug report from a user, a forum post), put it between `<!-- external -->` and `<!-- /external -->`. Intakt then shows it to models as material to look at, not as instructions to follow.
- **Libraries are not trusted either.** Code in your dependencies is treated like any other outside text: something to examine, never something to obey.

The technical version, role by role, is the [threat model](../design/05-architecture/14-threat-model.md).
