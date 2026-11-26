## Quorum policies

Plan task T8, part 1 (design: [Reviews](../design/05-architecture/07-reviews.md)). `Core/Review/Contract` has `QuorumPolicy`, a backed enum with the config strings `all`, `majority` and `one-strong-plus-one`, and `PanelVerdict` (the `Tier` of a reviewer, from `Core/Ai/Contract`, and its `Verdict`). `QuorumPolicy::isMet(list<PanelVerdict>)` (`#[NoDiscard]`) decides whether the panel meets the quorum. Each rule is a `static function` closure in the enum's private constant `RULES`, keyed by the case value (PHP 8.5 accepts a closure in a constant expression only as `static function`, not as an arrow function); `isMet()` hands it the approvals and the panel size. Only a `Decision::Approve` counts as an approval; `changes`, `blocked` and a stored `invalid-output` verdict never do, and an empty panel meets no policy. `ReviewChange`, `VerdictForTheLoop` and the dashboard's `LedgerRounds` hand it each reviewer's vote as the judge step counted and recorded it in `judge_done`, in which a `changes` verdict of only P3 findings the judge deferred or rejected is an approval and one whose findings are on no list is `blocked` (`Domain\CountedVote`, see [The outcome and the act list agree](review-flow.md)).

`PanelVerdict` also carries the reviewer's `ReviewLane` (`Code` by default). `ChangeDecision` hands `isMet()` only the `Code` votes and approves only when every `Docs` vote (the Technical writer's) approves as well ([Technical writer lane](technical-writer.md)).

- **all:** every verdict is an approval.
- **majority:** approvals are more than half of the panel (one of two is not a majority).
- **one-strong-plus-one:** at least two approvals, and at least one of them from a `Strong` reviewer.

`tests/Core/Review/Contract/QuorumPolicyTest.php` covers passing and failing panels of one, two and three. The panel configuration (part 2) and the rules choosing a panel per change (part 3) are in [Capability tiers per project](capability-tiers.md).
