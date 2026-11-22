# 1. Meet Intakt

*Chapter 1 of 9 in [the Intakt user guide](README.md).*

## Your agent says it is done

It is late afternoon. Your coding agent has spent an hour on a change: a shipping cost by weight and a report of a customer's orders. It ends with a cheerful summary. The tests pass, it says, and everything is done.

Should you believe it? The agent wrote the code and also judged it. You could read every line yourself, but then why have an agent at all?

## What Intakt does in one review

Intakt gives your change to a small panel of AI reviewers, from different AI labs, and lets each one read it on its own. Then a judge, the owl, goes through what they found and makes one short list of what to fix.

One review, from start to verdict:

1. **The change arrives.** A branch of a git repository and one sentence saying what it should do.
2. **The reviewers read it.** Each reviewer, the meerkat on the dashboard, reads the branch as it was when the review started, with read-only tools.
3. **Every finding must quote the code.** Before the judge sees a finding, plain code checks that the code it quotes is really in the change. A finding about a name nobody wrote is dropped.
4. **The owl judges.** It sorts the findings into fix now, follow up later, or reject.
5. **You get one verdict.** Approve, changes, or blocked, with the list To fix.

![The Now box of a judged review: two reviewers, correctness on Opus from Anthropic and security on GPT 6.2 Sol from OpenAI, both asking for changes; in the middle the list To fix with two P1, two P2 and one P3 findings, each with its id and text; on the right the owl and "The judge's call: Changes requested, 5 findings to fix"](../assets/guide/verdict-now-box.webp)

*The demo's review of that shipping change: two labs read it, the owl kept five findings.*

## Why two labs and not one

Every model has blind spots, and a model from the same lab often shares them. Two reviewers from different labs disagree in useful ways. In the review above, each reviewer found things the other missed, and both caught the same command injection.

The verdict itself comes from plain code, never from a reviewer's wording. Your project's panel has a rule, such as "every reviewer approves" or "a majority approves", and Intakt counts the votes against it.

## Why the list is short

Reviewers like to talk. The owl's job is to keep only what matters for this change:

- **P1** must be fixed, **P2** should be. Every P1 and P2 finding that passed the quote check stays on the list, whatever the owl thinks of it.
- **P3** is a small remark. It stays only when the owl marks it to fix now.
- When two reviewers report the same problem at the same place, the list shows it once.

So you read five findings instead of fifteen, and each one names a file and a line.

## How well it works

Intakt ships a review bench: small changes with planted defects. On 2026-11-21 a panel on Claude and GPT caught all 5 planted defects in each of 3 runs, 15 of 15. On low-cost open models it caught 11 of 15. The [bench page](../bench.md) shows how it was measured.

## What Intakt is not, yet

Today Intakt reviews branches of a local git repository, from the dashboard, the command line or your agent. Reviewing pull requests on GitHub and building tasks on its own are planned. This guide sticks to what works now.

Next: [The first five minutes](02-the-first-five-minutes.md)
