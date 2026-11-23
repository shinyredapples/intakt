# Showcase: a repository built by an AI loop

## What you're looking at

This repository is built by an AI loop. A builder takes an issue, writes the change and opens a pull request. A panel of reviewers from two AI companies, Claude and GPT, reviews every change. Probes give the reviewers evidence: small tests they write and run against the change. Plain code merges a change once CI is green and the reviewers approve. The owner steps in only where a person must: a change to the checks that judge the code, or a decision the loop cannot make.

One fact matters here: this repository is built by the build loop that developed Intakt, a Python loop Intakt was distilled from, not by Intakt itself. Intakt takes over its own building as its parts land (see [the plan](../plan.md)).

Everything below is public on this repository: the pull requests, the review comments, the labels and the owner's approvals.

## The figures

As of 2026-10-29 14:35 UTC (loop merges from 2026-10-27 to 2026-10-29). To refresh them, run:

```
docs/showcase/figures.sh
```

It needs `gh` with read access and `jq`, and only reads from the GitHub API.

| Figure | Value |
| --- | --- |
| Pull requests merged by the loop (`intakt-runner[bot]` as merger) | 60 |
| ... of those also opened by the loop | 59 |
| Issues closed by those merges | 59 |
| Review comments (Claude / GPT) | 401 (200 / 201) |
| Review rounds per merged pull request (median / max) | 2 / 14 |
| Findings (P1 / P2 / P3) | 784 (102 / 195 / 487) |
| Review comments that report a probe run (a "Probes:" line saying it ran) | 311 of 401 |
| Findings in a review that reports a probe run | 640 of 784 (82%) |
| Findings in a review that carried probe evidence (a fenced `probe` block) | 22 of 784 (3%) |
| Time from opening to merge (median) | 1.4 hours |
| Merges flagged for the owner's approval (`owner-approval` label: protected paths) | 25 of 60 |
| ... of those with an owner approval on record (a review or an `approve <sha>` comment) | 23 |
| Merges the owner approved without the flag | 0 |

How to read them:

- The loop acts as the GitHub App `intakt-runner`. It opens its pull requests and merges them itself. One more merge was a pull request the owner opened by hand. The owner merged 16 other pull requests by hand; they are not counted.
- The script counts the review rounds of a pull request as the larger of the two reviewers' review counts. Each round counts its findings again, so a finding that stays open for three rounds counts three times.
- Findings are graded P1 (blocks the merge), P2 (should be fixed) and P3 (a small follow-up).
- Reviewers report each probe on a line starting with `Probes:`. Since 2026-10-29 a reviewer can also attach the probe itself, as a fenced `probe` block, so the finding carries a test anyone can rerun. That is why the fenced-probe figure is still small.
- The `owner-approval` label marks a change that touched protected paths (the checks, CI, the agent rules). For two of those merges no owner approval is on record:
  - #162 was approved by the loop itself, by class: both reviewers classified its protected change (`AGENTS.md`) as tightening the rules.
  - #74's final diff touches no protected path; an earlier head did, and the label stayed on.
- Cost and token figures are kept by the loop, not shown here.

## Three examples

### A P1 the builder's tests had passed (#53)

Pull request #53 configures the AI providers, with keys from the environment or from Symfony secrets. The builder's tests passed. GPT's reviewer wrote its own probe for edge cases of the secrets vault and found one that broke the boot:

> [P1] `config/packages/ai.php:21`: Revealing the entire vault unconditionally can abort boot even when every provider credential is supplied through the environment. The probe confirmed that an unrelated vault entry plus a base64-valid, wrong-length `SYMFONY_DECRYPTION_SECRET` throws `SodiumException`

and summed it up as: "the probe passed all eight provider tests and four vault edge cases; two malformed-decryption-key cases failed during boot". The escalation reviewer reran the case and confirmed it. The next build fixed it. The [user guide](../reference/user-manual.md) now says that a secret Intakt cannot decrypt counts as not set.

### A repeated finding that went to escalation (#83)

Pull request #83 adds a command that prints the summary of a branch. Two rounds in a row flagged a P1 in the same command: first in how it counted changed lines, then because file and branch names reached Symfony's output formatter unescaped, so a file named `<info>note.txt` printed as `note.txt`. A P1 in the same place twice goes to the escalation reviewer, a stronger model. It confirmed the open one: "The finding holds on this head." A stronger model built the fix. In the next round GPT found a third P1 on the same lines: a file name can hold terminal control bytes that "erase or forge the displayed summary". The loop stopped and posted "**Escalation: a repeated P1 finding goes to the maintainer**".

The maintainer wrote a decision into issue #59: the earlier P1s were resolved, the new one was valid and needed a fix with a test that "must fail on the current head and pass after the fix". The next build did that. GPT raised one more P1, about error messages; the escalation reviewer checked it, found that it did not hold, and the change merged.

### The owner asked only for a protected change (#73)

Pull request #73 adds a PHPStan rule, so it changes the checks that judge every change. The loop does not let a change approve its own judge. Once CI was green and both reviewers approved, it posted:

> It touches protected paths (config/gates/phpstan.neon, config/gates/src/PhpStan/StatefulServiceResetRule.php, config/gates/src/ProcessRunner.php), so it merges only once the owner approves exactly this head

The owner approved. Later rounds changed the branch, and "a new push needs a new approval", so the loop asked again for the final head. The owner approved it at 15:29 UTC and the loop merged it at 15:33. Those two approvals were the owner's whole part in the change. Of the 60 loop merges, 35 needed no owner approval at all.

## What this shows

- **Reviewers from two companies catch different things.** In #53 and #83 the blocking finding came from a reviewer, not from the builder's tests.
- **Evidence beats opinion.** A probe turns "this looks wrong" into a failing case. The P1 in #53 was a run, not a guess.
- **Repeats are escalated, not looped forever.** A P1 seen twice goes to a stronger model; seen again, it goes to the maintainer, who decides once and writes the decision where the next build reads it.
- **The owner's time goes where only a person must decide.** The owner approved changes to the rules and nothing else.

## What changes when Intakt takes over its own loop

Today that loop runs every step, and Intakt is the code it builds. Intakt takes the loop over part by part, in the order of [the plan](../plan.md):

- **First the review.** The first release is the review loop: the two-company panel, probes, verdicts and a judge, shown live on a dashboard. Once it lands, Intakt reviews its own pull requests.
- **Then building and merging.** They come after the first release, as the [design](../design/README.md) describes. From then on, the figures on this page describe Intakt building itself.
- **Cost becomes visible.** Today Intakt's ledger records the tokens used by each model call, and the dashboard's review page shows the tokens per round. Recording the cost, and showing it there, are planned for the first release. That is where the figures missing above will live.
