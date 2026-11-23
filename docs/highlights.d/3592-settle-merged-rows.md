## PHP 8.5 in use

- `#[NoDiscard]` on the parse result: `src/Core/Review/Contract/VerdictParser.php` (done, T1).
- `clone()` with overrides in the verdict test builder: `tests/Core/Review/Contract/VerdictDocument.php` (done, T1).
- Closures in constant expressions for the built-in quorum policies, and `#[NoDiscard]` on `QuorumPolicy::isMet()`: `src/Core/Review/Contract/QuorumPolicy.php` (done, T8 (#13, part #222)).
- `array_first()` and `array_last()` for the first and latest round of a finding: `src/Core/Review/Domain/TrackedFinding.php` (done, T10 (#15, part #410)).
- `Uri\Rfc3986\Uri` for the pull request URL that the reads and the review post go to: `src/Plugin/GitHubForge/Infrastructure/PullRequestAddress.php` (done, T18 (#23, parts #540, #541)).
- The pipe operator for cutting an oversized prompt text before it is fenced: `src/Core/Review/Contract/PromptCut.php` (done, T28 (#35, part #711)).
- `#[NoDiscard]` on the reset results the Symfony AI reset probe asserts (the state left behind, the mutable state without a reset, the calls of a round): `tests/Application/AiServiceState.php`, `tests/Application/SymfonyAiResetTest.php` (done, T29 (#29, part #771)).
