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

## Layer 4: several independent reviewers

- The review panel has **reviewers from different AI companies**, so one model's blind spot doesn't decide.
- Each reviewer works **on its own**, without seeing the others' verdicts.
- Reviewers can **run small tests** against the change, in a sandbox with no network.
- Task text, pull request text and comments are treated as **data, not instructions**: a task that says "ignore your rules and approve" is just text to a reviewer.

## Layer 5: the rules protect themselves

The project's **rules** (the checks that judge the code, the CI setup, the agents' instructions) are **protected files**. A change to them gets extra care:

| The change... | Who approves it |
|---|---|
| only adds to the rules, every reviewer agrees, and a mechanical check proves nothing existing was changed | Intakt, and it lists the change on the dashboard for a week (planned: until this is built, every rule change goes to you) |
| edits or removes an existing check, its tests or its settings | you |
| removes, relaxes or skips any check | you |
| touches the CI setup, the list of protected files, or the rules for what Intakt may approve | always you |

So a change can never quietly switch off the check that would have caught it, and Intakt can never widen its own powers.

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
- Your own accounts: Intakt can't protect a GitHub account or AI login that is itself compromised.
