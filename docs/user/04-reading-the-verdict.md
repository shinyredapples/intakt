# 4. Reading the verdict

*Chapter 4 of 9 in [the Intakt user guide](README.md).*

## The owl has spoken

The review page stops moving. The owl's card reads "Changes needed", with "5 to fix" in large type, an urgency bar and the counts in words under it (for example "2 urgent, 2 should fix, 1 nice to have"), and one meerkat per reviewer marked with a check (approved) or a cross (changes needed); a reviewer without a verdict shows a dash and its status in words. Below it are the To fix cards. You have a list, and you want to know which item to open first and whether each one is real. This chapter reads the verdict with you, top to bottom.

## The judge's call

The owl's call is one of three outcomes:

- **Approve:** the panel's rule is met, for example every reviewer approved.
- **Changes:** a reviewer asked for changes, and at least one of its findings is on the list To fix.
- **Blocked:** no approval could be counted, for example because a reviewer failed or the round is a replay.

Plain code decides the outcome from the votes and the list, never from how a reviewer worded its answer. So a verdict of changes always names something to fix.

## The list To fix

Each card on the list is one thing to change. On the main view a card shows the urgency, the first sentence, the rest of the text cut to one line, and the place. Press a card to open the finding in the **Full record**, where the id, the other reviewers' reports and the owl's call are.

![Five finding cards from the list To fix: F1, P1, the discount check changed from "amount >= 100" to "amount > 100", at src/discount.js:3, also reported as F6, Fix now; F2, P1, command injection through customerName and execSync, at src/report.js:4, also reported as F5 and F7, Fix now; F3 and F4, P2, new functions without tests; F8, P3, a base charge written twice in src/shipping.js:6, Fix now](../assets/guide/findings.webp)

In the Full record, read a card from left to right:

1. **The urgency.** P1 must be fixed, P2 should be, P3 is a small remark.
2. **The id,** such as F1. It names this problem for the rest of the review.
3. **The text.** What is wrong, and often how to fix it.
4. **The place,** a file and a line, such as `src/discount.js:3`.
5. **Also reported as,** when another reviewer found the same problem at the same place. You fix it once. Each other id is listed once.
6. **The owl's call,** in plain words: **Fix now**.

Start with the P1 cards. In the demo, F1 brings back a discount bug and F2 lets a customer name run shell commands.

## Why every finding quotes the code

Reviewers sometimes report a problem in code that does not exist. Before the owl sees any finding, plain code reads the code it quotes between backticks and looks for those names in the change. A finding whose quotes are all missing is dropped.

A dropped finding still shows on the page, apart from the others, marked **Quote not found in the code**. Open its **Quoted code** to see what it quoted and the changed lines that come closest. File names, numbers and suggested-fix blocks are not checked.

## What the owl may drop, and what it may not

The owl marks each finding to fix now, to defer to a follow-up, or to reject. Its power has a limit: a P1 or P2 finding stays on the list whatever the owl marks it. Only a P3 leaves the list when the owl defers or rejects it. A P3 finding a reviewer marks as a gap in a `bin/check` gate is never on the list: it goes to the follow-ups, even when the owl says to fix it now, and only a reject drops it. Those cards read **Dropped: deferred to a follow-up issue** or **Dropped: rejected or not marked by the judge**.

## The next pass keeps the ids

You fix F1 and F2, commit to the same branch, and press **Start round 2** on the review page. The path grows: Pass 1, Fix, Pass 2.

In the second pass, every reviewer is shown the findings still open, with their ids. It marks the ones it finds fixed as resolved and the ones still there as repeats, under the same id. So F2 is F2 in every round, and you can see at a glance what is left.

From round two on, a new P1 or P2 is lowered to P3, unless the reviewer marks it a regression or names a requirement it breaks. Late surprises about code you did not touch stay small.

## The rest of the page

The **Full record** link at the bottom of the main view opens the rest of the page:

- **Timeline** lists every round. The newest judged round starts open; older ones fold to one line.
- **Reviewers** in each round shows each reviewer's model, outcome and number of findings.
- **Tokens and cost** shows what the round used. Chapter 7 explains the figures.
- **Live log** keeps what each reviewer read and said.

Next: [Let your agent drive](05-let-your-agent-drive.md)
