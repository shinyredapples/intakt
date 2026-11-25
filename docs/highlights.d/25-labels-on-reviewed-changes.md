## PHP 8.5 in use

- `array_last()` for the value applied last when a pull request carries two `model:` labels, and for the latest event on a status label, which decides whether Intakt comments on undoing a person's change: `src/Core/Review/Contract/ChangeDescription.php`, `src/Plugin/GitHubForge/Domain/StatusReconciliation.php` (done, #25).

## Symfony components

| Lock per pull request, not waited for | one writer of a pull request's `status:` label at a time; a writer that finds the lock held skips its write and logs it, and the next watcher pass reconciles the label to the stored round | done | T19 (#25), `src/Plugin/GitHubForge/Infrastructure/GitHubStatusLabels.php` |
