# Shared project memory

The goal is a memory that helps every vendor's agents, without letting any one of them poison it.
- **Written only by the orchestrator**, from accepted events: a merged pull request's summary, a review finding that was fixed, a recurring check failure and its cause.
- **Read-only to jobs**, mounted as a short markdown digest per project and role, regenerated after each merge, with a size cap.
- **Reviewed by the owner** in the dashboard: entries can be pinned, edited or deleted.
- **Fresh or flagged.** An entry that explains code records a content hash of the files it describes. When main changes them, the entry is flagged stale in the digest and on the dashboard until a job re-describes it, so no job reads an outdated explanation as fact.
- **Briefing at job start.** Each job starts with a short briefing: what the project is, what is in flight, recent merges and decisions, and the gotchas for the files it will touch.
