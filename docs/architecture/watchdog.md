# Watchdog over main

`Core/Watchdog` (issues #2969 and #2970, parts 1 and 2 of #2914) classifies main's new commits: the contract types in `src/Core/Watchdog/Contract/`, the classifier in `src/Core/Watchdog/Domain/` and `MainHistory`, which reads main with git, in `src/Core/Watchdog/Infrastructure/`. Nothing calls it yet: the console command, the project halt and the owner card come in later issues. The module has no container wiring, so `WatchdogServices` holds no ids yet.

## Rules

`CommitClassifier::classify(WatchedMain)` returns a `Classification`: clean, or a list of `MainViolation`s, each naming a commit and its `Violation`.

| Commit | Outcome |
| --- | --- |
| main's history no longer contains the last seen head (a force push rewrote it), whether `contains()` says so or the walk finds it (`MainHistoryRewritten`) | violation `history_rewritten`, named on the last seen head; no other commit is judged |
| a commit on the first-parent walk that is the head of a recorded merge | passes, before any signature is read |
| any other commit whose signature status is `owner_signed` (a key the owner registered) | passes |
| any other commit whose signature status is `unsigned` | violation `unsigned` |
| any other commit whose signature status is `unknown_key` (signed by a key the owner did not register) | violation `unknown_key` |

Every violating commit of the walk is listed, oldest first.

## Types

- `WatchedMain(lastSeenHead, list<RecordedMerge>)`: the head the watchdog saw last and the merges Intakt recorded. `isRecordedHead()` matches a commit against the recorded heads only, never the bases.
- `RecordedMerge(head, base)`: both must be full commit names (40 lowercase hexadecimal characters for SHA-1, 64 for SHA-256); anything else, an abbreviated name or a ref name included, throws `CommitNameNotFull`. `WatchedMain` checks its last seen head and `MainViolation` its commit the same way (`CommitName::full()`).
- `SignatureStatus`: a string-backed enum, `owner_signed`, `unsigned`, `unknown_key`.
- `Violation`: a string-backed enum, `unsigned`, `unknown_key`, `history_rewritten`.

## The history port

The classifier reads main only through `Contract/CommitHistory`, so its rules are tested on forced histories with a stub:
- `contains(commit)`: whether main still holds the commit; false after a force push dropped it.
- `firstParentsSince(commit)`: main's first-parent commits after it, oldest first. It throws `Contract/MainHistoryRewritten` when main no longer holds the commit, which happens when a force push lands after `contains()` answered. `CommitClassifier::classify()` catches it and returns the same `history_rewritten` violation on the last seen head as when `contains()` is false.
- `signatureOf(commit)`: the commit's `SignatureStatus`.

## Reading main with git

`MainHistory` (`src/Core/Watchdog/Infrastructure/MainHistory.php`) implements the port. It takes `HardenedGit`, the repository path and the owner's SSH public keys. Every git call goes through `HardenedGit::call()` (so the repository's hooks, replace refs, grafts and signature programs do not apply) and runs in the repository's real path: `run()` resolves a relative path with `realpath`. Every git and `ssh-keygen` call has the same 60-second timeout. Main is the branch `refs/heads/main`, never the checked-out head: each read first resolves that exact ref to its commit with `show-ref --verify --hash refs/heads/main`, so a missing branch throws even when a tag is named `refs/heads/main`. A git call that fails throws; it never becomes an answer. `contains()` and `firstParentsSince()` resolve that exact ref; `signatureOf()` reads the commit it is given.

- `contains(commit)`: `merge-base --is-ancestor <commit> <tip>`; exit 0 is true, exit 1 is false, any other exit (an unknown commit, for example) throws.
- `firstParentsSince(commit)`: resolves the tip once, checks `merge-base --is-ancestor <commit> <tip>` again on it and walks `rev-list --first-parent --reverse <commit>..<tip>` from the same tip. When main no longer holds the commit (a force push after `contains()`), it throws `MainHistoryRewritten` instead of walking, and the classifier reports `history_rewritten`, so the rewrite never reads as a clean main and never as a failed read.
- `signatureOf(commit)`: reads the object format (`rev-parse --show-object-format`) and the raw commit (`cat-file commit`). The headers are read line by line up to the first empty line, so a signature line in the message does not count. The signature header is `gpgsig` for SHA-1 and `gpgsig-sha256` for SHA-256; an unknown object format gives `unknown_key`. No header gives `unsigned`. Otherwise the signed payload is the commit without the header and its continuation lines, and `ssh-keygen -Y verify` checks the signature with namespace `git` against an allowed-signers file that holds only the owner's keys (temporary files, removed afterwards).

The check fails closed: only a successful `ssh-keygen -Y verify` gives `owner_signed`. A signature that is not an SSH signature (a PGP one, for example), two signature headers, no registered owner key, a failed check, a timeout or an `ssh-keygen` that does not start all give `unknown_key`.

Owner keys are strict: each must be one SSH public key, `<type> <base64>` with an optional comment without quotes or control characters. The key field must decode as Base64 to a whole key: length-prefixed fields that fill the bytes exactly, the first one the same type, then the fields of that type. These are a 32-byte key for `ssh-ed25519`; an exponent and a modulus, neither empty, for `ssh-rsa`, the modulus at least 1024 bits once its leading zero bytes are stripped (OpenSSH's own minimum); the curve name and a point of 65, 97 or 133 bytes for `ecdsa-sha2-nistp256`, `-nistp384` and `-nistp521`, whose first byte must be `0x04` (uncompressed, the only form OpenSSH reads) and which OpenSSL must decode as a public key on that curve (`openssl_pkey_get_public`), so a point off the curve is refused; and, for a key on a security key (the type `sk-<plain type>@openssh.com`, for `ssh-ed25519` and `ecdsa-sha2-nistp256` only), the plain type's fields plus an application that is not empty. Anything else (a second line, an allowed-signers option such as `cert-authority`, a quote, a key field that is not Base64, names another type, is cut short, has bytes left over or is of a type not listed here) throws `OwnerKeyRefused` when `MainHistory` is built, so no key can add a line or an option to the allowed-signers file. The comment is dropped; only the type and the key go into the file.

Tests: `tests/Core/Watchdog/Infrastructure/MainHistoryTest.php`, on temporary repositories with forced histories and real `ssh-keygen` keys (a recorded merge is clean, a seeded unsigned commit is an `unsigned` violation, an owner-signed commit passes, a foreign key is an `unknown_key` violation, a PGP signature header and a signed commit without registered keys are `unknown_key`, a force push is `history_rewritten`, a SHA-256 repository's `gpgsig-sha256` header verifies, first parents come oldest first, a relative path reads the same, malformed owner keys are refused (a key with one byte where it declares 32, an RSA modulus of zero or of 1023 bits, an ECDSA point in hybrid form and an ECDSA point off its curve among them), an owner key of each listed type is accepted, a 1024-bit RSA modulus and an uncompressed point on its curve are accepted, a missing main branch throws even with a tag of its full name, a force push between `contains()` and the walk is classified `history_rewritten` on the last seen head, the walk from a dropped commit throws `MainHistoryRewritten`, the allowed-signers file is removed when the second temporary file cannot be allocated). They need `ssh-keygen` and are never skipped without it.

Tests of the rules: `tests/Core/Watchdog/Domain/CommitClassifierTest.php` (a recorded merge is clean, a recorded head passes even when unsigned, an owner-signed commit passes, an unsigned commit and a foreign key are violations, a force push is `history_rewritten`, also when only the walk finds the seen head dropped), `tests/Core/Watchdog/Contract/RecordedMergeTest.php` and `tests/Core/Watchdog/Contract/WatchedMainTest.php`.
