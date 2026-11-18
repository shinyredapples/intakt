# Designer scoring layers (planned)

How the Designer ([Reviews](07-reviews.md#the-designer-planned), [Overview](01-overview.md#the-team)) judges user-facing work, in three layers: objective checks that block, a heuristic rubric with evidence, and taste learned from the owner by pairwise comparison. Each layer does what the one before it cannot: a check settles what a tool can measure, the rubric covers usability a tool cannot measure, and the taste ladder covers choices where no rule says which is better. Like the role itself, the scoring runs only for a user-facing task of a project with a user interface. Nothing on this page is built yet.

## Layer 1: objective checks that block

Each item is a check, not a reviewer's judgement, because a rule a tool can check is a gate rule ([Principles](../02-principles.md), "Coding rules live in gates"). A failing check blocks the merge like every other check; it does not hold the other reviewers. Only the Designer's own scoring waits for them: it scores a head once its Layer 1 checks have run, since the rubric reads their screenshots and results.

- **Accessibility:** no serious or critical axe-core violations, and passing color contrast.
- **Layout at each width** (phone, tablet, desktop): no horizontal scroll, no overlapping elements, no text overflow, and touch targets of at least 44 px.
- **Design tokens:** colors, spacing and type come only from the project's design tokens.
- **Lighthouse:** each page meets the project's Lighthouse thresholds for that page.
- **Screenshots against main:** each screen at each width is rendered at the head and compared with its reference screenshot on main, and unintended changes are flagged.

The screenshot rule is mechanical, so no model decides what was intended. References are files in the repository, one per screen and width. A screen whose rendering differs from main's reference by more than the project's threshold counts as intended only when the change updates that reference in the same change, so the diff shows the old and the new look to every reviewer and the Designer scores the new one. Otherwise the check fails and names each screen and width that changed. An updated reference must match the head's own rendering within the same threshold, so a change cannot replace a reference with an image it does not produce.

The thresholds and the list of pages are the project's check settings, which live in protected files like every check's configuration, so a change cannot loosen the checks that judge it.

## Layer 2: a rubric with evidence

The Designer scores each screen a change touches against fixed usability heuristics: clarity, feedback, states, consistency, error recovery and copy.

- **Every score cites evidence:** a region of a screenshot and what is wrong there. A score without evidence is an invalid answer.
- **A low score is a review finding, not a gate.** It reaches the change as a finding of the Designer's review and is handled like any other finding ([Reviews](07-reviews.md)); it never fails a check.
- **Scores are kept per screen over time,** so a screen that gets worse shows as a regression on the [dashboard](13-dashboard.md).

## Layer 3: taste by blind pairwise comparison

Some choices are visible but not wrong either way (a layout, an emphasis, a tone of copy). For these the Designer learns the owner's taste instead of guessing it.

- **Variants and a ladder:** for a visible design choice the Designer creates variants and ranks them in pairs into a preference ladder.
- **What a variant is:** the Designer writes no code. It creates a variant as a typed description (the choice, the screen, and what differs, such as a token value, a layout or a line of copy). A build job implements each variant as a commit of its own on top of the head; those commits are kept by the orchestrator, never pushed to the change's branch and never merged. The check environment renders each one with the same tools as Layer 1, and the rendered screenshots are what the Designer ranks and the owner sees. Variant commits pass the same hand-back checks as any build, so a variant that fails them is dropped and shown, never ranked.
- **The owner votes blind** on a sample of those pairs: the owner sees two variants' screenshots, in an order plain code picks at random, without knowing which one the Designer preferred.
- **Agreement is measured and shown:** how often the Designer's ranking matches the owner's votes, shown on the [dashboard](13-dashboard.md).
- **Asking follows agreement.** While agreement is low, choices with character go to the owner as options; as it rises, fewer are asked.

## Tools

The tools these layers need (axe-core, Lighthouse, a headless browser) are opt-in per project and run in the check environment, never in the Designer's own session, so the simplest install needs none of them ([Reviews](07-reviews.md#the-designer-planned)).
