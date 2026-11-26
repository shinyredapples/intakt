## Technical writer lane

The Technical writer (`technical_writer`) reviews the docs part of a change for its readers, in a lane of its own beside the Code reviewers ([design](../design/05-architecture/07-reviews.md#the-technical-writer), [threat model](../design/05-architecture/14-threat-model.md#the-technical-writers-session)).

### When it joins

A project turns it on with a `technical_writer` section under `review:` (`PanelConfiguration`: `tier`, `provider`, `prompt`, `budget`, and `paths`, default `docs/user/**`, `docs/guide/**`, `README.md`). `ConfiguredPanels::panelForChange()` adds it after the core panel and the rules when `Domain\TechnicalWriterTrigger` matches the `ChangeDescription`:

1. The change must touch a page people read (`Contract\DocsPages`: `docs/**/*.md` or `README.md`). Without one it never joins, label or not.
2. Then one of: a changed path matches `paths`; a changed page is in `ChangeDescription::$summaries`; or the change carries `review:technical_writer`.

`$summaries` is filled by `LocalGitForge\Infrastructure\LocalChangeDescription`, which `intakt:review` and the identity check share. For each changed page it compares the "In short" section of the base and the head (`Contract\SummarySection`: the section from its heading to the next heading of the same or a higher level, headings inside code fences ignored; an absent page is empty). The labels are the trusted ones a watched pull request passes into its round (`ReviewChangeInput::$labels`); `intakt:review:panel --label` shows the choice without a model.

The role name `technical_writer` is refused for a reviewer of the panel or of a rule, so only the trigger puts a reviewer in the docs lane (`Contract\ReviewLane::ofRole()`).

### What it is given

`ReviewWithReviewerHandler` cuts the diff to the docs pages (`Infrastructure\DocsPartOfDiff`, which keeps a file section when any path in its header is a page) and starts a `ReviewerConversation` in the `Docs` lane. `ReviewerPrompt` then uses `TECHNICAL_WRITER_INSTRUCTIONS` through `PromptAssembly::systemWithRole()`, fences the task, the docs part and the open findings on docs pages (`openFor()`; `OpenFindingIds` refuses an answer naming any other id) like any reviewer input and adds no project memory notes, also when the project memory has notes on the pages. The handler also refuses, as an unreadable verdict that stores nothing, a new finding that has no file or names a file outside `DocsPages`, so the writer never records a finding on code. The tools are the reviewers' read-only toolbox at the head; none writes. Retries keep the lane.

### How its vote counts

Its verdict has the reviewers' shape and parser. `Contract\ChangeDecision` counts the quorum over the `Code` lane only and approves only when every `Docs` vote approves too, so it can send a change back but never replaces a Code reviewer's approval. `Panel` checks that the quorum is reachable the same way.

### Proposals

A finding whose requirement starts with `Proposal:` is made a P3 that only the owner can fix (`Domain\OwnerProposals`, applied in `ReviewerAgent` before the approval guards and in the handler before the verdict is stored). `Contract\JudgedFindings` never puts such a finding (`Finding::proposesToOwner()`) on the act list: marked act or defer it is a follow-up, marked reject it is rejected. A proposal that repeats an open finding is refused as unreadable, because the goalpost rule would give it the open finding's blocking severity. Filing proposals as tasks waits for the follow-up inbox.

Tests: `TechnicalWriterTriggerTest`, `SummarySectionTest`, `LocalChangeDescriptionTest`, `ConfiguredPanelsTest`, `PanelConfigurationTest`, `DocsPartOfDiffTest`, `ReviewerPromptDocsLaneTest`, `TechnicalWriterLaneTest`, `ChangeDecisionTest`, `OwnerProposalsTest`, `JudgedFindingsTest`, `HashedReviewPromptsTest` (the reviewer prompt hash covers `TECHNICAL_WRITER_INSTRUCTIONS` too).
