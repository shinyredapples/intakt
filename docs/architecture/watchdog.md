# Watchdog: classifying main's new commits

`Core/Watchdog` reads the commits that arrived on main since the head last seen and classifies each one. It only reads and classifies; the halt, the owner card and a console command come in later parts. The use case is `CheckMain::of(WatchedMain)`, which returns a `CheckedMain` (the head, and one `CommitClassification` per commit, oldest first).

`WatchedMain` holds the repository path, the last seen head (null on the first look), the `RecordedMerge` list and the owner's public keys. A `RecordedMerge(head, base)` holds two full commit names (40 or 64 lowercase hexadecimal characters, so a name never reaches git as an option or a range); anything else throws `InvalidArgumentException`. The last seen head is validated the same way. An owner key is its type and its base64 body separated by one space (`ssh-ed25519 AAAA...`), printable characters only and without a comment, so a key can never add a line to the allowed-signers file.

## Rules

| Case | Verdict | Violation |
|---|---|---|
| The commit is the head of a recorded merge | `recorded_merge` | no |
| The commit is signed by a key the owner registered | `owner_signed` | no |
| The commit has no signature | `unsigned` | yes |
| The commit has a signature that no owner key verifies (another key, a broken signature, no owner key registered) | `unknown_key` | yes |
| The last seen head is not an ancestor of main's head, or is not in the repository at all (a force push) | `history_rewritten` | yes |

`SignatureStatus` (`good`, `unsigned`, `unknown_key`) is a string-backed enum; `Verdict::ofSignature()` maps it to the verdict. A commit on the first-parent walk is clean as a recorded head before its signature is looked at; every other commit is judged by its signature. A rewritten history is answered with one classification, `history_rewritten` for the new head, because the commits since an earlier head that is no ancestor cannot be listed. `git merge-base --is-ancestor` answers with exit 0 (an ancestor) or 1 (not one); on any other exit `git cat-file -e` tells whether the last seen head is there: absent is `history_rewritten`, present but unreadable (a corrupt object) is `MainNotRead`, never a verdict.

## Signatures

The signature is read from the header git uses for the repository's object format: `gpgsig` for a 40-character commit name (SHA-1), `gpgsig-sha256` for a 64-character one (SHA-256). It is checked by `ssh-keygen -Y verify` (namespace `git`, at most 60 seconds) against an allowed-signers file made of the owner's keys alone, over the commit text without any signature header, as git signs it. `git log --show-signature` is not used: the hardened git call (`HardenedGit`) switches git's signature programs off on purpose. An exit of `ssh-keygen` other than success or refusal (not installed, killed, timed out) ends in `MainNotRead`, never in a verdict, so a missing program cannot read as "unknown key". Tests need `ssh-keygen` and do not skip without it.

## A shallow repository is refused

`MainHistory::headOf()` first runs `git rev-parse --is-shallow-repository` and throws `MainNotRead` unless it prints `false`, before `CheckMain` lists any commit: a shallow repository ends its walk early, so missing history would look clean. A repository path that does not exist, and a git call that fails or does not finish, are `MainNotRead` too. The path is resolved with `realpath`, so a relative one works.
