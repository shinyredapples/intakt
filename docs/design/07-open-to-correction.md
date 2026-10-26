# Design calls open to correction

## Open questions

1. Shared project memory: should entries expire, or be compacted by a periodic summary job?
2. Vendor terms: which subscription plans allow this kind of automated use, checked per vendor before the public release (see [Vendor terms](04-constraints.md#vendor-terms)).

## Calls made on thin evidence

These are decided, but a measured result from a running install may overturn them. Change them through a decision record, not silently.

- The default review panel of two reviewers from different vendors ([Reviews](05-architecture/07-reviews.md)).
- The start rule of the model planner: the cheaper model first, escalation on a blocking finding or a stall, a small random holdout ([Budget and model planner](05-architecture/06-budget-and-model-planner.md)).
- The size tiers and the plan-approval thresholds ([Work intake](05-architecture/03-work-intake.md), decision 7).
- Serializing tasks only at high overlap-forecast confidence (decision 8).
- The default label families and their names ([Labels](05-architecture/04-labels.md)).
