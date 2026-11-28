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
- <picture><source media="(prefers-color-scheme: dark)" srcset="../../assets/images/characters/technical_writer-dark.webp"><img src="../../assets/images/characters/technical_writer-light.webp" alt="A heron with a notebook under its wing" width="48" height="48"></picture> **The heron, technical writer.** A new member of the cast, after the tortoise in the README's team picture. Its review of the docs people read is still planned, so you will not meet it on the dashboard yet.

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

## When a task is too big

You run `intakt:build:size` on a task, and the answer is `split`: the task is too big for one build.

1. Intakt records its plan first. Then it files two to four new issues, the parts. Each part has the task's labels and waits for the parts it builds on.
2. The task stays open as their umbrella, with one comment naming the parts. It is never built itself: `intakt:queue:show` lists it as held, and the parts as tasks. Editing it later does not size it again.
3. If the command stops halfway, run it again. It finishes from the plan and files no part twice.
4. A task is split only once, and a part is never split again ([details](../reference/user-manual.md#sizing-a-new-task)).

## Which tier a build runs on

When Intakt builds a task, these inputs decide its tier, the first that applies wins:

1. A path the build touches matches one of the project's sensitive paths: strong.
2. An escalation review confirmed a recurring P1 on the change's latest head: strong. A rejected escalation changes nothing.
3. An earlier round had a setback the project escalates on: strong.
4. A `model:<tier>` label on the task.
5. The project's default tier.

`intakt:build:tier <project> --change=<change>` shows the choice and its reason; no build lane passes the change yet ([details](../reference/user-manual.md#which-tier-a-build-starts-on)).

Before a build's pull request goes out, you can check its text yourself: `intakt:check:pull-request-text` takes the task and the pull request text, and says which Evidence items the text does not name and whether the body is too long; it records nothing. A build runs the same check before its push: a first miss stops the build before its push, with nothing pushed and the commits kept in the build folder, and a second in a row goes on to the reviewers. Planned: the build lane hands a first miss back to the Developer to redo only the text ([the command and an example](../reference/user-manual.md#the-check-before-a-push)).

One thing to know about booting: Intakt can start a change in a scratch copy to see that it boots. A change that takes too long to install or to start counts as a failed boot, not as a fault of Intakt, so when main boots fine, the change can be sent back. Details are in the [complete reference](../reference/user-manual.md).

The helper-order check of `bin/check` does not recognize a call from a property hook, a `[$this, 'helper']` callable array or a `$this::helper()` call, so a private helper reached only that way is never reported ([the method-order rules](../reference/user-manual.md#when-bincheck-reports-the-order-of-methods)).

`bin/check` refuses a catch in `src/` that takes any failure instead of naming the ones it expects ([generic catches](../reference/user-manual.md#when-bincheck-reports-a-generic-catch)).

The `phpstan` check of `bin/check` wants test methods named as sentences: `test` followed by at least three camel-case words, such as `testTheFormIsSent()`. A data provider is exempt only when every concrete test class that runs it names it as a provider; a class that inherits it without naming it runs it as a plain test, so it is reported (renaming it without the `test` prefix avoids that) ([the test-name rule](../reference/user-manual.md#when-bincheck-reports-a-test-method-name)).

A reviewer's role prompt must come from the owner's panel configuration or the sample project's fixed roles, so the `phpstan` gate of `bin/check` limits where the objects that carry it are built: `new ReviewerRole` only in `ConfiguredPanels` and `SampleProject`, `new RoundReviewer` only in `ReviewRound` (`intakt.reviewerRole`), and `new ReviewerConversation` only in `ReviewWithReviewerHandler` and `SymfonyAiVendor` (`intakt.reviewerConversation`). A class string typed with an interface or parent class of one of these objects, such as `class-string<HasPlace>`, counts as naming that object; one typed with several (`class-string<HasPlace&Countable>`) counts only when the object has every one of them. Code under `tests/` is not checked ([details](../reference/user-manual.md#when-the-phpstan-gate-reports-a-prompt-message-finding)).

Code in a trait counts as outside, even when an allowed class uses the trait. In a trait that a class uses, `new self`, `new static` or `new $class` is judged in each class that uses it, and is refused when it may build one of these objects or its type names no class. In a trait no class uses, all three are refused, except a `new self` or `new static` inside an anonymous class in the trait, which builds that anonymous class. An anonymous class (`new class () {}`) builds none of these objects and passes.

Looking for a setting, a command option or an exact rule? The [complete reference](../reference/user-manual.md) keeps every detail.

The `left-undone` check of `bin/check` refuses a new TODO, FIXME or XXX comment in the PHP, Twig, JavaScript, shell and YAML lines a change adds.

Text that is not a comment does not count: a string, a line indented under a YAML `run: |`, or a shell here-document body (from `cat <<EOF` up to the `EOF` line). A comment after such a body still counts.

A comment in the form `TODO(#123): <reason>` that names an issue is allowed ([examples and exceptions](../reference/user-manual.md#when-the-left-undone-gate-reports-a-todo-comment-a-skipped-test-or-a-not-implemented-throw)).

`intakt:build:task` runs its steps in this order and stops at the first one that fails: task, mirror, clone, build job, hand-back check, pre-push check, push, pull request. The pull request step opens or updates the branch's pull request under Intakt's GitHub App after the push; when GitHub refuses it, the branch stays pushed and the task page and `intakt:status` show `pull_request` as the failed step with GitHub's reason ([building one task](../reference/user-manual.md#building-one-task)).

The `ignored` check of `bin/check` refuses a tracked file that `.gitignore` excludes. Untrack it with `git --literal-pathspecs rm --cached -- '<path>'`, the path in single quotes with each `'` in it written as `'\''` (the quotes and the option keep a name such as `build[1].tmp` from matching other files), or keep it with a negated rule in `.gitignore` ([how](../reference/user-manual.md#when-the-ignored-gate-reports-a-tracked-file-that-gitignore-excludes)). It also fails on a tracked `.gitignore` you changed or deleted without staging, since CI judges the committed rules: stage or revert that file.

One rule to know before your first build: for a project with a `github` entry, the `--remote` of `intakt:build:task` must be that configured GitHub repository's `https://github.com/<owner>/<name>` address, or the command refuses before it does anything ([the `intakt:build:task` options](../reference/user-manual.md#building-one-task)).

Before it hands a change back, the Developer can run Intakt's own check in the build job (`sh /input/precheck.sh`). It reports the refusals Intakt would otherwise give after the hand-back: no new commit on the task's branch, a branch that is not a fast-forward, a new merge commit, a commit git cannot write and a missing `PR.md`. The Developer fixes them in the same session. Intakt's own check after the hand-back still decides ([the builder's own check](../architecture/build-job.md#the-builders-own-check)).

A task with the `bug` label describes the bug in four parts: When, Today, Expected and Must keep working. If one is missing or empty, Intakt builds nothing and parks the task with a question that names the missing part, shown on the task's page in the dashboard ([bug tasks](../reference/user-manual.md#bug-tasks)).

When all four parts are there, the Developer writes the failing regression test first, commits it on its own, then fixes the bug. A build without that test, or whose test also passes on the base, fails before the push like a red check; otherwise the results on the base and on the head are written into the pull request's Evidence (an Evidence heading underlined with `===` or `---` gets them too; code and HTML blocks are skipped); a bug no test can reproduce goes in without the `bug` label ([bug tasks](../reference/user-manual.md#bug-tasks), [writing a bug task](../guide/writing-a-good-task.md#writing-a-bug-task)).

For a `bug` task, once the project's check has passed and only when the work adds, changes or renames a test, Intakt tries to run those tests twice before the push (a run that cannot start counts as having no result): on the old code (they should fail) and on the fixed code (they should pass). It finds them in the work itself, not in the Developer's word.

Intakt chooses tasks only from GitHub issues that carry the project's eligibility label: `intakt`, unless the project's `watch` section names another `eligibility_label`. An issue that carries only another loop's label is not a task for Intakt. `php bin/console intakt:queue:show <project>` prints the order Intakt would pick the tasks in (tasks with an open pull request, including one found only by its task branch, come before tasks not started yet, also urgent ones), then the held tasks with their reasons; no build starts from it yet ([details](../reference/user-manual.md#showing-the-queue-of-tasks)).

When that other loop merges into the same main, give its signing key to the watchdog commands with `--peer-key`. Its merges then show as `info: peer merge <commit> peer_signed` and halt nothing ([Running beside another loop](../reference/user-manual.md#running-beside-another-loop)).

When Intakt picks a task from a project's GitHub issues, it reads the issue once more right before. If the issue changed since the listing in a way that steers the queue, nothing is picked that round, and the next round reads the issue again from scratch: its labels changed, someone without write access applied a label last, priority labels were applied in another order (the one applied last counts), or the issues its text waits for or closes changed. A change to the wording alone, the same references in another order, or two other labels applied again in the other order does not stop the pick.

The [plain-words guides](../guide/README.md) explain how Intakt works inside.

Next: [Meet Intakt](01-meet-intakt.md)
