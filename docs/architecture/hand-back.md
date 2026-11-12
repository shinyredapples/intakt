# Hand-back checks

A build hands back a git bundle. The module `Core/HandBack` (plan task T50, first part) checks that bundle before anything trusts it, and strips attribution from its commits. The secret scan, the redaction of mounted credentials, the send-back once and the hand-over on a second failure are later parts of T50 and are not built.

`php bin/console intakt:hand-back:check <repository> <branch> <bundle> [--merge-main]` (`CheckHandBackCommand`) calls the use case. It prints `accepted <head>`, `base <commit>` and `rewritten <count>` and exits with 0; a refusal prints `refused: <reason>` in an error block and exits with 1; an `InvalidArgumentException` or a `ReportableFailure` (`HandBackNotChecked`) prints its message and exits with 1. Nothing calls the use case from another module yet: the push (T53) and the build lane (T56) will.

## Contract

`src/Core/HandBack/Contract/`:

- `HandBack`: the repository (a bare repository whose `HEAD` names main, such as the project's [mirror](mirror.md)), the task's branch, the bundle's path and `mergeOfMainAsked`.
- `AcceptedHandBack` (`base`, `head`, `rewrittenCommits`) and `RefusedHandBack` (a `HandBackRefusal`) are the two results of a check.
- `HandBackRefusal` is a backed enum; `reason()` is fixed text per case, so a reason never holds a value from the refused bundle.
- `HandBackNotChecked` (a `ReportableFailure`) is thrown when the repository cannot be read; its message names the repository and the git subcommand, never git's own output, which may quote the bundle.

The check itself, `CheckHandBack::check(HandBack)` (marked `#[NoDiscard]`), lives in `src/Core/HandBack/UseCase/CheckHandBack/`, not in the Contract, so only the module's own console door calls it. The push (T53) and the build lane (T56) need an entry point in the Contract first; it is added with the first of them.

## The checks, in order

```mermaid
flowchart TD
  start[HandBack] --> repo{HEAD names main,<br/>branch name valid, not main?}
  repo -- no --> error[Exception]
  repo -- yes --> header{Header readable?}
  header -- no --> unreadable[Unreadable]
  header -- yes --> main{Names main?}
  main -- yes --> touches[TouchesMain]
  main -- no --> refs{Only the branch?}
  refs -- no --> other[NamesAnotherRef]
  refs -- yes --> base{Prerequisites on<br/>the branch or main?}
  base -- no --> notOn[NotOnBranch]
  base -- yes --> unbundle{Unbundles?}
  unbundle -- no --> unreadable
  unbundle -- yes --> ff{Fast-forward<br/>of the base?}
  ff -- no --> notFf[NotFastForward]
  ff -- yes --> merges{New merges?}
  merges -- "yes, not asked" --> holds[HoldsMerge]
  merges -- "yes, asked, a parent not on main" --> mergesOther[MergesAnotherBranch]
  merges -- "none, or merges of main" --> strip{Strip attribution;<br/>every new commit<br/>passes git's format check?}
  strip -- no --> malformed[Malformed]
  strip -- yes --> accepted[AcceptedHandBack]
```

1. The repository path is resolved to an absolute one (`realpath`), because git runs inside the repository; a path that is no folder is `HandBackNotChecked`. Main is the branch the repository's `HEAD` names (`symbolic-ref --quiet HEAD`, under `refs/heads/`); otherwise `HandBackNotChecked`. The branch must pass `check-ref-format --branch` with the name itself as the answer, and must not be main; otherwise `InvalidArgumentException`.
2. The base is the pushed branch (`refs/heads/<branch>`), or main's tip when the repository does not hold the branch yet, as in the [build clone](build-clone.md).
3. `BundleFile` reads at most `BundleFile::HEADER_LIMIT` (1 MiB) plus one byte, and the header must end (an empty line) within the limit. `BundleHeader::parse()` in `Domain/` reads it by `gitformat-bundle(5)`: the v2 or v3 signature, in v3 only the `@object-format=sha1` or `sha256` capability (a `@filter` bundle leaves objects out and is refused), prerequisites `-<id>[ <comment>]` and references `<id> <ref>`, with ids in lowercase hex of the format's length and no ref named twice. Anything else is `Unreadable`. Intakt reads the header itself, because git prints prerequisites only as prose; every id it passes to git has passed that pattern.
4. A ref equal to main is `TouchesMain`; any set of refs other than the branch alone is `NamesAnotherRef`.
5. Every prerequisite must be an ancestor of the base or of main (`merge-base --is-ancestor`, which also fails for a commit the repository does not hold); otherwise `NotOnBranch`.
6. `git bundle unbundle <absolute path>` writes the bundle's objects into the repository and no ref; a failure is `Unreadable`.
7. The head (the branch's id in the bundle) must be a commit (`cat-file -t`) and a descendant of the base; otherwise `NotFastForward`.
8. New commits are `rev-list <head> ^<base> ^<main>`: main's commits are never new, so a merge of main brings them in unchanged. A new merge commit is `HoldsMerge` unless a merge of main was asked; then each parent after the first must be on main, otherwise `MergesAnotherBranch`. The first parent is the branch's side, as `git merge main` on the branch writes it.
9. `StrippedCommits` walks the new commits oldest first (`--reverse --topo-order`). `AttributionLines::strippedFrom()` removes every line that is a `Co-authored-by:` trailer or starts with `Generated with`, `Generated by` or `Generated-by:` (any case, after any marks that are not letters or digits), byte-wise so a message in another encoding is read too; then runs of blank lines collapse to one and trailing white space goes. A message that loses no line is kept byte for byte. A commit whose message or parents changed is written again (`hash-object -t commit -w --stdin`) with its parents mapped to the rewritten ones and without `gpgsig` or `gpgsig-sha256` headers, since the orchestrator signs before the push; a commit with nothing to change keeps its id and signature. `rewrittenCommits` counts the commits that got a new id. Every new commit passes git's own format check, because unbundling checks none: a rewritten one when it is written, an unchanged one by hashing it again without writing (`hash-object -t commit --stdin`). A commit that fails it (for example one with no author) is `Malformed`, with or without attribution.

Every git call is built by `HardenedGit` with `--git-dir=<repository>` and `GIT_WORK_TREE` unset, through `RepositoryGit`. The check writes objects only, so a mirror update in between loses nothing; the returned `base` lets the push name the branch state it was checked against.
